<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
$logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name = $logged_in ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routes - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/user.css">
    <style>
        .route-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            background-color: white;
        }
        
        .route-header {
            background-color: #1d3557;
            color: white;
            padding: 15px;
            font-weight: 600;
        }
        
        .route-body {
            padding: 15px;
        }
        
        .route-icon {
            background-color: #f8f9fa;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #1d3557;
            margin-right: 15px;
        }
        
        .route-info {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .route-details {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .route-details:last-child {
            border-bottom: none;
        }
        
        .city-label {
            background-color: #ffb100;
            color: #1d3557;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .stops-container {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .route-stops {
            list-style-type: none;
            padding-left: 0;
            margin-bottom: 0;
        }
        
        .route-stops li {
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
            display: flex;
            align-items: center;
        }
        
        .route-stops li:last-child {
            border-bottom: none;
        }
        
        .route-stops li i {
            margin-right: 10px;
            color: #ffb100;
        }
        
        .route-filter {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .no-routes-found {
            background-color: #f8f9fa;
            padding: 2rem;
            border-radius: 10px;
            text-align: center;
            display: none;
        }

        .reverse-route-label {
            position: relative;
            display: none;
            margin-bottom: 10px;
            padding: 5px 12px;
            font-size: 0.85rem;
        }

        .section-title {
            margin-top: 2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #ffb100;
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
                        <a class="nav-link" href="../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="routes.php">Routes</a>
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
                    <?php if ($logged_in): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($user_name); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="my_bookings.php"><i class="fas fa-ticket-alt me-2"></i>My Bookings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item ms-2">
                        <a class="btn btn-outline-light" href="login.php">Login</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section" style="background-image: url('../assets/Ceres_Bus.JPG'); height: 250px;">
        <div class="container text-center">
            <h1>Ceres Bus Routes</h1>
            <p class="lead">Explore all available Ceres Bus routes across Western Visayas</p>
        </div>
    </div>

    <div class="container py-5">
        <!-- Route Finder -->
        <div class="route-filter">
            <h4 class="mb-3"><i class="fas fa-search me-2"></i>Find Your Route</h4>
            <form class="row g-3" id="routeFilterForm">
                <div class="col-md-5">
                    <label for="origin" class="form-label">From</label>
                    <select class="form-select" id="origin">
                        <option value="" selected>All Origins</option>
                        <option value="iloilo-city">Iloilo City</option>
                        <option value="roxas-city">Roxas City</option>
                        <option value="kalibo">Kalibo</option>
                        <option value="san-jose">San Jose (Antique)</option>
                        <option value="bacolod">Bacolod City</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="destination" class="form-label">To</label>
                    <select class="form-select" id="destination">
                        <option value="" selected>All Destinations</option>
                        <option value="iloilo-city">Iloilo City</option>
                        <option value="roxas-city">Roxas City</option>
                        <option value="kalibo">Kalibo</option>
                        <option value="san-jose">San Jose (Antique)</option>
                        <option value="bacolod">Bacolod City</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-warning w-100">Filter</button>
                </div>
            </form>
        </div>

        <!-- No routes found message -->
        <div class="no-routes-found" id="noRoutesFound">
            <i class="fas fa-route fa-3x text-muted mb-3"></i>
            <h4>No Routes Found</h4>
            <p>There are no routes matching your search criteria. Please try different locations.</p>
        </div>

        <div id="routeGroups">
            <h2 class="section-title" id="iloCityHeader">Routes from Iloilo City</h2>
            <div class="route-group" data-origin="iloilo-city">
                <!-- Iloilo to Roxas Route -->
                <div class="route-card" data-origin="iloilo-city" data-destination="roxas-city">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Iloilo City to Roxas City (Capiz) and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Iloilo City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">Roxas City</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 155 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 3-4 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱180-220</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Iloilo City Tagbak Terminal</li>
                                <li><i class="fas fa-circle"></i>Passi City</li>
                                <li><i class="fas fa-circle"></i>Dumarao</li>
                                <li><i class="fas fa-circle"></i>Cuartero</li>
                                <li><i class="fas fa-circle"></i>Dao</li>
                                <li><i class="fas fa-circle"></i>Panay</li>
                                <li><i class="fas fa-circle"></i>Roxas City Integrated Terminal</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=iloilo-roxas" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=iloilo-roxas" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Iloilo to Kalibo Route -->
                <div class="route-card" data-origin="iloilo-city" data-destination="kalibo">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Iloilo City to Kalibo (Aklan) and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Iloilo City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">Kalibo</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 158 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 3.5-4.5 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱220-250</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Iloilo City Tagbak Terminal</li>
                                <li><i class="fas fa-circle"></i>Passi City</li>
                                <li><i class="fas fa-circle"></i>Dumarao</li>
                                <li><i class="fas fa-circle"></i>Dao</li>
                                <li><i class="fas fa-circle"></i>Sigma</li>
                                <li><i class="fas fa-circle"></i>Mambusao</li>
                                <li><i class="fas fa-circle"></i>Banga</li>
                                <li><i class="fas fa-circle"></i>Kalibo Integrated Bus Terminal</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=iloilo-kalibo" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=iloilo-kalibo" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Iloilo to Boracay/Caticlan Route -->
                <div class="route-card" data-origin="iloilo-city" data-destination="caticlan">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Iloilo City to Caticlan (Boracay) and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Iloilo City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">Caticlan (Boracay)</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 195 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 4-5 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱280-350</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Iloilo City Tagbak Terminal</li>
                                <li><i class="fas fa-circle"></i>Passi City</li>
                                <li><i class="fas fa-circle"></i>Banga</li>
                                <li><i class="fas fa-circle"></i>Kalibo</li>
                                <li><i class="fas fa-circle"></i>Nabas</li>
                                <li><i class="fas fa-circle"></i>Malay</li>
                                <li><i class="fas fa-circle"></i>Caticlan Jetty Port</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=iloilo-caticlan" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=iloilo-caticlan" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Iloilo to San Jose Antique Route -->
                <div class="route-card" data-origin="iloilo-city" data-destination="san-jose">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Iloilo City to San Jose (Antique) and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Iloilo City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">San Jose (Antique)</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 140 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 3-4 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱180-220</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Iloilo City Molo Terminal</li>
                                <li><i class="fas fa-circle"></i>Oton</li>
                                <li><i class="fas fa-circle"></i>Tigbauan</li>
                                <li><i class="fas fa-circle"></i>Guimbal</li>
                                <li><i class="fas fa-circle"></i>Miagao</li>
                                <li><i class="fas fa-circle"></i>San Joaquin</li>
                                <li><i class="fas fa-circle"></i>Hamtic</li>
                                <li><i class="fas fa-circle"></i>San Jose, Antique Bus Terminal</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=iloilo-sanjose" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=iloilo-sanjose" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <h2 class="section-title" id="bacCityHeader">Routes from Bacolod City</h2>
            <div class="route-group" data-origin="bacolod">
                <!-- Bacolod to San Carlos Route -->
                <div class="route-card" data-origin="bacolod" data-destination="san-carlos">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Bacolod City to San Carlos City and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Bacolod City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">San Carlos City</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 85 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 2-2.5 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱130-150</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Bacolod North Terminal</li>
                                <li><i class="fas fa-circle"></i>Talisay</li>
                                <li><i class="fas fa-circle"></i>Silay</li>
                                <li><i class="fas fa-circle"></i>Victorias</li>
                                <li><i class="fas fa-circle"></i>Cadiz</li>
                                <li><i class="fas fa-circle"></i>Sagay</li>
                                <li><i class="fas fa-circle"></i>San Carlos City Bus Terminal</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=bacolod-sancarlos" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=bacolod-sancarlos" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Bacolod to Dumaguete Route -->
                <div class="route-card" data-origin="bacolod" data-destination="dumaguete">
                    <div class="route-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Bacolod City to Dumaguete City and Vice Versa</h4>
                    </div>
                    <div class="route-body">
                        <div class="route-info">
                            <div class="route-icon">
                                <i class="fas fa-map-marked-alt"></i>
                            </div>
                            <div>
                                <div class="city-label">Bacolod City</div>
                                <div class="fw-bold fs-5 mb-2">to</div>
                                <div class="city-label">Dumaguete City</div>
                            </div>
                        </div>
                        
                        <div class="route-details">
                            <div><i class="fas fa-road me-2"></i><strong>Distance:</strong> 217 km</div>
                            <div><i class="fas fa-clock me-2"></i><strong>Travel Time:</strong> 5-6 hours</div>
                            <div><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱300-350</div>
                        </div>
                        
                        <div class="stops-container">
                            <h5><i class="fas fa-map-pin me-2"></i>Stops Along This Route:</h5>
                            <ul class="route-stops">
                                <li><i class="fas fa-circle"></i>Bacolod South Terminal</li>
                                <li><i class="fas fa-circle"></i>Bago City</li>
                                <li><i class="fas fa-circle"></i>Binalbagan</li>
                                <li><i class="fas fa-circle"></i>Hinoba-an</li>
                                <li><i class="fas fa-circle"></i>Bayawan</li>
                                <li><i class="fas fa-circle"></i>Sta. Catalina</li>
                                <li><i class="fas fa-circle"></i>Siaton</li>
                                <li><i class="fas fa-circle"></i>Dumaguete City Integrated Terminal</li>
                            </ul>
                        </div>
                        
                        <div class="text-end mt-3">
                            <a href="schedule.php?route=bacolod-dumaguete" class="btn btn-outline-primary me-2">
                                <i class="fas fa-clock me-1"></i>View Schedule
                            </a>
                            <a href="booking.php?route=bacolod-dumaguete" class="btn btn-warning">
                                <i class="fas fa-ticket-alt me-1"></i>Book Now
                            </a>
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
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const routeFilterForm = document.getElementById('routeFilterForm');
            const originSelect = document.getElementById('origin');
            const destinationSelect = document.getElementById('destination');
            const routeCards = document.querySelectorAll('.route-card');
            const noRoutesFound = document.getElementById('noRoutesFound');
            const iloCityHeader = document.getElementById('iloCityHeader');
            const bacCityHeader = document.getElementById('bacCityHeader');
            const routeGroups = document.querySelectorAll('.route-group');

            // Get URL parameters if any (for direct links)
            const urlParams = new URLSearchParams(window.location.search);
            const urlOrigin = urlParams.get('origin');
            const urlDestination = urlParams.get('destination');
            
            // Set select values if URL parameters exist
            if (urlOrigin) {
                originSelect.value = urlOrigin;
            }
            if (urlDestination) {
                destinationSelect.value = urlDestination;
            }
            
            // If URL parameters exist, filter routes immediately
            if (urlOrigin || urlDestination) {
                filterRoutes();
            }

            // Filter routes function
            function filterRoutes() {
                const origin = originSelect.value;
                const destination = destinationSelect.value;
                
                let visibleRoutes = 0;
                let originVisibility = {
                    'iloilo-city': false,
                    'bacolod': false
                };
                
                // Reset all cards visibility first
                routeCards.forEach(card => {
                    const cardOrigin = card.getAttribute('data-origin');
                    const cardDestination = card.getAttribute('data-destination');
                    
                    // Check if card matches filter criteria (direct or reverse)
                    let showCard = true;
                    let isReverseRoute = false;
                    
                    if (origin && destination) {
                        // When both origin and destination are specified
                        // Check for direct match OR reverse match
                        if ((cardOrigin === origin && cardDestination === destination) || 
                            (cardOrigin === destination && cardDestination === origin)) {
                            showCard = true;
                            if (cardOrigin === destination && cardDestination === origin) {
                                isReverseRoute = true;
                                
                                // Add a "Reverse Route" badge if it's the opposite direction
                                let reverseLabel = card.querySelector('.reverse-route-label');
                                if (!reverseLabel) {
                                    reverseLabel = document.createElement('div');
                                    reverseLabel.className = 'reverse-route-label badge bg-info text-white mb-2';
                                    reverseLabel.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>Reverse Route';
                                    card.querySelector('.route-body').prepend(reverseLabel);
                                }
                                reverseLabel.style.display = 'inline-block';
                            }
                        } else {
                            showCard = false;
                        }
                    } else if (origin) {
                        // Only origin specified
                        showCard = (cardOrigin === origin || cardDestination === origin);
                        if (cardDestination === origin) {
                            isReverseRoute = true;
                        }
                    } else if (destination) {
                        // Only destination specified
                        showCard = (cardDestination === destination || cardOrigin === destination);
                        if (cardOrigin === destination) {
                            isReverseRoute = true;
                        }
                    }
                    
                    // Show or hide the card based on filtering
                    if (showCard) {
                        card.style.display = 'block';
                        visibleRoutes++;
                        originVisibility[cardOrigin] = true;
                        
                        // If it's a reverse route, mark it
                        if (isReverseRoute) {
                            let reverseLabel = card.querySelector('.reverse-route-label');
                            if (!reverseLabel) {
                                reverseLabel = document.createElement('div');
                                reverseLabel.className = 'reverse-route-label badge bg-info text-white mb-2';
                                reverseLabel.innerHTML = '<i class="fas fa-exchange-alt me-1"></i>Reverse Route';
                                card.querySelector('.route-body').prepend(reverseLabel);
                            }
                            reverseLabel.style.display = 'inline-block';
                        } else {
                            // Hide reverse route label if it exists but not needed
                            let reverseLabel = card.querySelector('.reverse-route-label');
                            if (reverseLabel) {
                                reverseLabel.style.display = 'none';
                            }
                        }
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Show or hide section headers based on visible content
                routeGroups.forEach(group => {
                    const groupOrigin = group.getAttribute('data-origin');
                    const visibleCardsInGroup = group.querySelectorAll('.route-card[style="display: block;"]').length;
                    
                    if (visibleCardsInGroup > 0) {
                        originVisibility[groupOrigin] = true;
                    }
                });
                
                iloCityHeader.style.display = originVisibility['iloilo-city'] ? 'block' : 'none';
                bacCityHeader.style.display = originVisibility['bacolod'] ? 'block' : 'none';
                
                // Show "no routes found" message if no routes visible
                if (visibleRoutes === 0) {
                    noRoutesFound.style.display = 'block';
                } else {
                    noRoutesFound.style.display = 'none';
                }
            }
            
            // Handle form submission
            routeFilterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                filterRoutes();
                
                // Update URL with filter parameters (for bookmarking/sharing)
                const origin = originSelect.value;
                const destination = destinationSelect.value;
                let url = 'routes.php';
                let params = [];
                
                if (origin) params.push(`origin=${origin}`);
                if (destination) params.push(`destination=${destination}`);
                
                if (params.length > 0) {
                    url += '?' + params.join('&');
                    window.history.pushState({}, '', url);
                }
            });
            
            // Reset filters when user selects "All Origins" or "All Destinations"
            originSelect.addEventListener('change', function() {
                if (originSelect.value === '' && destinationSelect.value === '') {
                    resetFilters();
                }
            });
            
            destinationSelect.addEventListener('change', function() {
                if (originSelect.value === '' && destinationSelect.value === '') {
                    resetFilters();
                }
            });
            
            // Reset filters function
            function resetFilters() {
                // Show all routes
                routeCards.forEach(card => {
                    card.style.display = 'block';
                    
                    // Hide all reverse route labels
                    let reverseLabel = card.querySelector('.reverse-route-label');
                    if (reverseLabel) {
                        reverseLabel.style.display = 'none';
                    }
                });
                
                // Show all section headers
                iloCityHeader.style.display = 'block';
                bacCityHeader.style.display = 'block';
                
                // Hide no routes message
                noRoutesFound.style.display = 'none';
                
                // Update URL (remove parameters)
                window.history.pushState({}, '', 'routes.php');
            }
        });
    </script>
</body>
</html>