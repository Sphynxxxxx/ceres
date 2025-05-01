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
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Database connection
require_once "../../backend/connections/config.php"; 
require_once "../../vendor/autoload.php";
require_once "../../backend/connections/fare_calculator.php";
$fareCalculator = new FareCalculator();

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch routes data from database
$routes = [];
try {
    $query = "SELECT r.*, 
              (SELECT COUNT(*) FROM buses b WHERE LOWER(b.route_name) = CONCAT(LOWER(r.origin), ' → ', LOWER(r.destination))) as bus_count 
              FROM routes r 
              ORDER BY r.origin, r.destination";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate fare based on distance
            $fareRange = $fareCalculator->getFareRange($row['distance']);
            $row['regular_fare'] = $fareRange['regular'];
            $row['discounted_fare'] = $fareRange['discounted'];
            $routes[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle exception
    error_log("Error fetching routes data: " . $e->getMessage());
}

// Get route locations for map
$locations = [];
if (count($routes) > 0) {
    foreach ($routes as $route) {
        // Add origin and destination to locations if not already included
        if (!array_key_exists($route['origin'], $locations)) {
            // Using approximate coordinates for Iloilo and nearby cities
            // In a real system, you would store actual coordinates in the database
            switch (strtolower($route['origin'])) {
                case 'iloilo':
                    $locations[$route['origin']] = ['lat' => 10.7202, 'lng' => 122.5621];
                    break;
                case 'roxas':
                    $locations[$route['origin']] = ['lat' => 11.5850, 'lng' => 122.7519];
                    break;
                case 'kalibo':
                    $locations[$route['origin']] = ['lat' => 11.7086, 'lng' => 122.3648];
                    break;
                default:
                    // Default coordinates for unknown locations (center of Panay Island)
                    $locations[$route['origin']] = ['lat' => 11.2500, 'lng' => 122.5000];
            }
        }
        
        if (!array_key_exists($route['destination'], $locations)) {
            switch (strtolower($route['destination'])) {
                case 'iloilo':
                    $locations[$route['destination']] = ['lat' => 10.7202, 'lng' => 122.5621];
                    break;
                case 'roxas':
                    $locations[$route['destination']] = ['lat' => 11.5850, 'lng' => 122.7519];
                    break;
                case 'kalibo':
                    $locations[$route['destination']] = ['lat' => 11.7086, 'lng' => 122.3648];
                    break;
                default:
                    // Default coordinates for unknown locations
                    $locations[$route['destination']] = ['lat' => 11.2500, 'lng' => 122.5000];
            }
        }
    }
}

// Convert to JSON for JavaScript
$locationsJson = json_encode($locations);
$routesJson = json_encode($routes);

// Generate fare table for display
$fareTable = $fareCalculator->getFareTable(150, 20);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routes - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link href="../css/navfot.css" rel="stylesheet">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>   
    <style>
        #map-container {
            height: 400px;
            border-radius: 0.25rem;
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .route-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 20px;
            border: none;
            border-radius: 10px;
            overflow: hidden;
        }
        .route-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .route-header {
            background-color: #ffc107;
            color: #212529;
            font-weight: 600;
            border-bottom: 0;
        }
        .route-body {
            background-color: #fff;
            padding: 1.25rem;
        }
        .route-detail {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .route-detail i {
            width: 24px;
            margin-right: 10px;
            color: #ffc107;
        }
        .cta-button {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
            font-weight: 500;
            padding: 0.5rem 1.5rem;
        }
        .cta-button:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            color: #212529;
        }
        .route-icon {
            font-size: 36px;
            color: #ffc107;
            margin-bottom: 15px;
        }
        .route-filter {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .fare-table {
            max-width: 100%;
            overflow-x: auto;
        }
        .fare-table th, .fare-table td {
            text-align: center;
        }
        .discounted-price {
            color: #198754;
            font-size: 0.9rem;
            display: block;
        }
        .fare-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 30px;
        }
        .fare-info {
            padding: 8px 12px;
            background-color: #f8f9fa;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-top: 10px;
            color: #6c757d;
        }
        .fare-badge {
            font-size: 0.8rem;
            padding: 3px 8px;
            border-radius: 10px;
            background-color: #e9ecef;
            margin-left: 5px;
            color: #495057;
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
                        <a class="nav-link active" href="routes.php">Routes</a>
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

    <div class="container py-4">
        <!-- Fare Information Card -->
        <div class="card fare-card mb-4">
            <div class="card-header">
                <h4 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Fare Information</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-info-circle me-2"></i> Bus fares are calculated based on distance. The base fare is ₱12.00 for the first 4km with an additional ₱1.80 per kilometer thereafter.
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 mb-3 mb-md-0">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-user-graduate text-primary fa-3x mb-3"></i>
                                <h5>Student Discount</h5>
                                <p class="mb-1">20% off regular fare</p>
                                <div class="fare-info">
                                    Valid student ID required
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3 mb-md-0">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-user-plus text-success fa-3x mb-3"></i>
                                <h5>Senior Citizen Discount</h5>
                                <p class="mb-1">20% off regular fare</p>
                                <div class="fare-info">
                                    Senior Citizen ID required
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-wheelchair text-danger fa-3x mb-3"></i>
                                <h5>PWD Discount</h5>
                                <p class="mb-1">20% off regular fare</p>
                                <div class="fare-info">
                                    PWD ID required
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Fare Table -->
                <h5 class="mb-3"><i class="fas fa-table me-2"></i>Sample Fare Table</h5>
                <div class="fare-table">
                    <table class="table table-striped table-bordered">
                        <thead class="table-warning">
                            <tr>
                                <th>Distance (km)</th>
                                <th>Regular Fare</th>
                                <th>Student/PWD/Senior Fare</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fareTable as $fare): ?>
                            <tr>
                                <td><?php echo $fare['distance']; ?> km</td>
                                <td>₱<?php echo number_format($fare['regular'], 2); ?></td>
                                <td>₱<?php echo number_format($fare['student'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted">* Fares are subject to change without prior notice</small>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-route me-2"></i>Available Routes</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i> View all available Ceres bus routes connecting ISAT-U campuses and key locations.
                        </div>
                        
                        <!-- Route Map -->
                        <h5 class="mb-3"><i class="fas fa-map-marked-alt me-2"></i>Route Map</h5>
                        <div id="map-container"></div>
                        
                        <!-- Route Filter -->
                        <div class="route-filter">
                            <div class="row">
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <label for="origin-filter" class="form-label"><i class="fas fa-map-marker-alt me-2"></i>Origin</label>
                                    <select class="form-select" id="origin-filter">
                                        <option value="">All Origins</option>
                                        <?php
                                        $origins = array_unique(array_column($routes, 'origin'));
                                        sort($origins);
                                        foreach ($origins as $origin) {
                                            echo '<option value="' . strtolower($origin) . '">' . ucfirst($origin) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <label for="destination-filter" class="form-label"><i class="fas fa-map-pin me-2"></i>Destination</label>
                                    <select class="form-select" id="destination-filter">
                                        <option value="">All Destinations</option>
                                        <?php
                                        $destinations = array_unique(array_column($routes, 'destination'));
                                        sort($destinations);
                                        foreach ($destinations as $destination) {
                                            echo '<option value="' . strtolower($destination) . '">' . ucfirst($destination) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="sort-options" class="form-label"><i class="fas fa-sort me-2"></i>Sort By</label>
                                    <select class="form-select" id="sort-options">
                                        <option value="origin">Origin (A-Z)</option>
                                        <option value="destination">Destination (A-Z)</option>
                                        <option value="distance-asc">Distance (Low to High)</option>
                                        <option value="distance-desc">Distance (High to Low)</option>
                                        <option value="fare-asc">Fare (Low to High)</option>
                                        <option value="fare-desc">Fare (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Routes Display -->
                        <div class="row" id="routes-container">
                            <?php if (count($routes) > 0): ?>
                                <?php foreach ($routes as $route): ?>
                                    <div class="col-md-6 route-item" 
                                         data-origin="<?php echo strtolower($route['origin']); ?>" 
                                         data-destination="<?php echo strtolower($route['destination']); ?>"
                                         data-distance="<?php echo $route['distance']; ?>"
                                         data-fare="<?php echo $route['regular_fare']; ?>">
                                        <div class="card route-card">
                                            <div class="card-header route-header">
                                                <h5 class="mb-0">
                                                    <i class="fas fa-bus me-2"></i>
                                                    <?php echo ucfirst($route['origin']); ?> to <?php echo ucfirst($route['destination']); ?>
                                                </h5>
                                            </div>
                                            <div class="card-body route-body">
                                                <div class="route-detail">
                                                    <i class="fas fa-map-marked-alt"></i>
                                                    <span><strong>Route:</strong> <?php echo ucfirst($route['origin']); ?> → <?php echo ucfirst($route['destination']); ?></span>
                                                </div>
                                                <div class="route-detail">
                                                    <i class="fas fa-road"></i>
                                                    <span><strong>Distance:</strong> <?php echo $route['distance']; ?> km</span>
                                                </div>
                                                <div class="route-detail">
                                                    <i class="fas fa-clock"></i>
                                                    <span><strong>Est. Travel Time:</strong> <?php echo $route['estimated_duration']; ?></span>
                                                </div>
                                                <div class="route-detail">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                    <span>
                                                        <strong>Fare:</strong> ₱<?php echo number_format($route['regular_fare'], 2); ?>
                                                        <span class="discounted-price">Discounted: ₱<?php echo number_format($route['discounted_fare'], 2); ?> 
                                                            <span class="fare-badge">20% off</span>
                                                        </span>
                                                    </span>
                                                </div>
                                                <div class="route-detail">
                                                    <i class="fas fa-bus-alt"></i>
                                                    <span><strong>Available Buses:</strong> <?php echo $route['bus_count']; ?></span>
                                                </div>
                                                <div class="d-grid gap-2 mt-3">
                                                    <a href="booking.php?origin=<?php echo urlencode($route['origin']); ?>&destination=<?php echo urlencode($route['destination']); ?>" class="btn cta-button">
                                                        <i class="fas fa-ticket-alt me-2"></i>Book This Route
                                                    </a>
                                                    <button class="btn btn-outline-secondary showOnMap" 
                                                            data-origin="<?php echo $route['origin']; ?>" 
                                                            data-destination="<?php echo $route['destination']; ?>">
                                                        <i class="fas fa-map-marker-alt me-2"></i>Show on Map
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>No routes found in the system.
                                    </div>
                                </div>
                            <?php endif; ?>
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
                <p class="copyright">&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Map initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Parse JSON data from PHP
            const locations = <?php echo $locationsJson; ?>;
            const routes = <?php echo $routesJson; ?>;
            
            // Initialize map
            const map = L.map('map-container').setView([11.2500, 122.5000], 8); // Center of Panay Island
            
            // Add tile layer (OpenStreetMap)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Store markers and polylines
            const markers = {};
            const polylines = [];
            
            // Add markers for each location
            for (const [name, coords] of Object.entries(locations)) {
                const marker = L.marker([coords.lat, coords.lng])
                    .addTo(map)
                    .bindPopup(`<b>${name.charAt(0).toUpperCase() + name.slice(1)}</b><br>Bus Terminal`);
                
                markers[name.toLowerCase()] = marker;
            }
            
            // Function to draw route on map
            function drawRoute(origin, destination) {
                // Clear previous polylines
                polylines.forEach(polyline => map.removeLayer(polyline));
                polylines.length = 0;
                
                // Reset all markers
                for (const marker of Object.values(markers)) {
                    marker.setIcon(L.icon({
                        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                        shadowSize: [41, 41]
                    }));
                }
                
                const originLower = origin.toLowerCase();
                const destinationLower = destination.toLowerCase();
                
                if (markers[originLower] && markers[destinationLower]) {
                    // Highlight origin and destination markers
                    const yellowIcon = L.icon({
                        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-gold.png',
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41]
                    });
                    
                    markers[originLower].setIcon(yellowIcon);
                    markers[destinationLower].setIcon(yellowIcon);
                    
                    // Draw line between origin and destination
                    const originCoords = [locations[origin].lat, locations[origin].lng];
                    const destCoords = [locations[destination].lat, locations[destination].lng];
                    
                    const polyline = L.polyline([originCoords, destCoords], {
                        color: '#ffc107',
                        weight: 5,
                        opacity: 0.7,
                        dashArray: '10, 10'
                    }).addTo(map);
                    
                    polylines.push(polyline);
                    
                    // Fit map to show the route
                    const bounds = L.latLngBounds(originCoords, destCoords);
                    map.fitBounds(bounds, { padding: [50, 50] });
                    
                    // Find route info
                    const routeInfo = routes.find(r => 
                        r.origin.toLowerCase() === originLower && 
                        r.destination.toLowerCase() === destinationLower
                    );
                    
                    if (routeInfo) {
                        // Add route info popup at midpoint
                        const midLat = (originCoords[0] + destCoords[0]) / 2;
                        const midLng = (originCoords[1] + destCoords[1]) / 2;
                        
                        L.popup()
                            .setLatLng([midLat, midLng])
                            .setContent(`
                                <h6><strong>${origin.charAt(0).toUpperCase() + origin.slice(1)} to ${destination.charAt(0).toUpperCase() + destination.slice(1)}</strong></h6>
                                <p>Distance: ${routeInfo.distance} km<br>
                                Travel Time: ${routeInfo.estimated_duration}<br>
                                Regular Fare: ₱${parseFloat(routeInfo.regular_fare).toFixed(2)}<br>
                                Discounted: ₱${parseFloat(routeInfo.discounted_fare).toFixed(2)}</p>
                            `)
                            .openOn(map);
                    }
                }
            }
            
            // Show route on map when button is clicked
            document.querySelectorAll('.showOnMap').forEach(button => {
                button.addEventListener('click', function() {
                    const origin = this.getAttribute('data-origin');
                    const destination = this.getAttribute('data-destination');
                    drawRoute(origin, destination);
                });
            });
            
            // Filter and sorting functionality
            const originFilter = document.getElementById('origin-filter');
            const destinationFilter = document.getElementById('destination-filter');
            const sortOptions = document.getElementById('sort-options');
            const routesContainer = document.getElementById('routes-container');
            
            function filterAndSortRoutes() {
                const originValue = originFilter.value;
                const destinationValue = destinationFilter.value;
                const sortValue = sortOptions.value;
                
                // Get all route items
                const routeItems = document.querySelectorAll('.route-item');
                
                // Hide all routes first
                routeItems.forEach(item => {
                    item.style.display = 'none';
                });
                
                // Filter routes
                const filteredRoutes = Array.from(routeItems).filter(item => {
                    const itemOrigin = item.getAttribute('data-origin');
                    const itemDestination = item.getAttribute('data-destination');
                    
                    const originMatch = !originValue || itemOrigin === originValue;
                    const destinationMatch = !destinationValue || itemDestination === destinationValue;
                    
                    return originMatch && destinationMatch;
                });
                
                // Sort routes
                filteredRoutes.sort((a, b) => {
                    switch(sortValue) {
                        case 'origin':
                            return a.getAttribute('data-origin').localeCompare(b.getAttribute('data-origin'));
                        case 'destination':
                            return a.getAttribute('data-destination').localeCompare(b.getAttribute('data-destination'));
                        case 'distance-asc':
                            return parseFloat(a.getAttribute('data-distance')) - parseFloat(b.getAttribute('data-distance'));
                        case 'distance-desc':
                            return parseFloat(b.getAttribute('data-distance')) - parseFloat(a.getAttribute('data-distance'));
                        case 'fare-asc':
                            return parseFloat(a.getAttribute('data-fare')) - parseFloat(b.getAttribute('data-fare'));
                        case 'fare-desc':
                            return parseFloat(b.getAttribute('data-fare')) - parseFloat(a.getAttribute('data-fare'));
                        default:
                            return 0;
                    }
                });
                
                // Show filtered and sorted routes
                filteredRoutes.forEach(item => {
                    item.style.display = 'block';
                    routesContainer.appendChild(item);
                });
                
                // Show no results message if needed
                if (filteredRoutes.length === 0) {
                    const noResults = document.createElement('div');
                    noResults.className = 'col-12';
                    noResults.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>No routes found matching your criteria.
                        </div>
                    `;
                    routesContainer.appendChild(noResults);
                }
            }
            
            // Add event listeners for filter and sort
            originFilter.addEventListener('change', filterAndSortRoutes);
            destinationFilter.addEventListener('change', filterAndSortRoutes);
            sortOptions.addEventListener('change', filterAndSortRoutes);
            
            // Initialize with all routes showing
            filterAndSortRoutes();
        });
    </script>
</body>
</html>