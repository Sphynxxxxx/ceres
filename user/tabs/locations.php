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

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get unique locations from routes
$locations = [];
try {
    // Get all origins and destinations from routes
    $query = "SELECT DISTINCT origin as location FROM routes 
              UNION 
              SELECT DISTINCT destination as location FROM routes 
              ORDER BY location";
    
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $locations[] = $row['location'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching locations: " . $e->getMessage());
}

// Get routes for each location to show connectivity
$location_routes = [];
try {
    foreach ($locations as $location) {
        $query = "SELECT 
                    r.id,
                    r.origin,
                    r.destination,
                    r.distance,
                    r.estimated_duration,
                    r.fare,
                    COUNT(DISTINCT b.id) as active_buses,
                    COUNT(DISTINCT s.id) as active_schedules
                FROM routes r
                LEFT JOIN buses b ON b.route_name = CONCAT(r.origin, ' → ', r.destination) AND b.status = 'Active'
                LEFT JOIN schedules s ON s.origin = r.origin AND s.destination = r.destination AND s.status = 'active'
                WHERE r.origin = ? OR r.destination = ?
                GROUP BY r.id, r.origin, r.destination, r.distance, r.estimated_duration, r.fare
                ORDER BY r.origin, r.destination";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ss", $location, $location);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $routes = [];
            while ($row = $result->fetch_assoc()) {
                $routes[] = $row;
            }
            $location_routes[$location] = $routes;
            $stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error fetching location routes: " . $e->getMessage());
}

// Define major terminals and their details - EXPANDED
$terminals = [
    // Iloilo City
    'iloilo' => [
        'name' => 'Iloilo City Terminal (Tagbak)',
        'address' => 'Ceres Bus Terminal, Tagbak, Jaro, Iloilo City',
        'contact' => '(033) 337-8888',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls', 'Parking Area'],
        'operating_hours' => '3:00 AM - 10:00 PM Daily',
        'region' => 'Iloilo City'
    ],
    'passi' => [
        'name' => 'Passi City Terminal',
        'address' => 'Passi City, Iloilo',
        'contact' => '(033) 311-7777',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 9:00 PM Daily',
        'region' => 'Iloilo City'
    ],
    'molo' => [
        'name' => 'Molo Terminal',
        'address' => 'Molo, Iloilo City',
        'contact' => '(033) 336-7777',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 8:00 PM Daily',
        'region' => 'Iloilo City'
    ],
    
    // Negros Occidental
    'bacolod' => [
        'name' => 'Sambok Terminal',
        'address' => 'Bacolod City, Negros Occidental',
        'contact' => '(034) 434-8888',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls', 'Parking Area'],
        'operating_hours' => '4:00 AM - 10:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'la_castellana' => [
        'name' => 'La Castellana Terminal',
        'address' => 'La Castellana, Negros Occidental',
        'contact' => '(034) 473-2222',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 7:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'sagay' => [
        'name' => 'Sagay City Terminal',
        'address' => 'Sagay City, Negros Occidental',
        'contact' => '(034) 488-3333',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 8:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'san_carlos' => [
        'name' => 'San Carlos Terminal',
        'address' => 'San Carlos City, Negros Occidental',
        'contact' => '(034) 312-4444',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 9:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'victorias' => [
        'name' => 'Victorias Terminal',
        'address' => 'Victorias City, Negros Occidental',
        'contact' => '(034) 399-5555',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 8:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'cadiz' => [
        'name' => 'Cadiz Terminal',
        'address' => 'Cadiz City, Negros Occidental',
        'contact' => '(034) 493-6666',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 9:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'hinobaan' => [
        'name' => 'Hinoba-an Terminal',
        'address' => 'Hinoba-an, Negros Occidental',
        'contact' => '(034) 497-7777',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 7:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'kabankalan' => [
        'name' => 'Kabankalan Terminal',
        'address' => 'Kabankalan City, Negros Occidental',
        'contact' => '(034) 471-8888',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 8:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    'escalante' => [
        'name' => 'Escalante Terminal',
        'address' => 'Escalante City, Negros Occidental',
        'contact' => '(034) 454-9999',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 8:00 PM Daily',
        'region' => 'Negros Occidental'
    ],
    
    // Capiz
    'sigma' => [
        'name' => 'Sigma Terminal',
        'address' => 'Sigma, Capiz',
        'contact' => '(036) 622-3333',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 7:00 PM Daily',
        'region' => 'Capiz'
    ],
    'roxas' => [
        'name' => 'Roxas City Terminal',
        'address' => 'Ceres Terminal, Arnaldo Boulevard, Roxas City, Capiz',
        'contact' => '(036) 621-8888',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '3:00 AM - 9:00 PM Daily',
        'region' => 'Capiz'
    ],
    
    // Aklan
    'aklan' => [
        'name' => 'Kalibo Terminal',
        'address' => 'Ceres Bus Terminal, Kalibo, Aklan',
        'contact' => '(036) 268-8888',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls', 'Parking Area'],
        'operating_hours' => '3:00 AM - 9:00 PM Daily',
        'region' => 'Aklan'
    ],
    'caticlan' => [
        'name' => 'Caticlan Terminal',
        'address' => 'Malay (near Boracay Jetty Port), Aklan',
        'contact' => '(036) 288-1111',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms', 'Food Stalls'],
        'operating_hours' => '4:00 AM - 10:00 PM Daily',
        'region' => 'Aklan'
    ],
    'banga' => [
        'name' => 'Banga Terminal',
        'address' => 'Banga, Aklan',
        'contact' => '(036) 267-2222',
        'facilities' => ['Waiting Area', 'Ticket Booths', 'Rest Rooms'],
        'operating_hours' => '5:00 AM - 7:00 PM Daily',
        'region' => 'Aklan'
    ]
];

// Group terminals by region for better display
$terminals_by_region = [];
foreach ($terminals as $key => $terminal) {
    $region = $terminal['region'];
    if (!isset($terminals_by_region[$region])) {
        $terminals_by_region[$region] = [];
    }
    $terminals_by_region[$region][$key] = $terminal;
}

// ISAT-U Campus Locations
$campus_stops = [
    [
        'name' => 'ISAT-U Main Campus',
        'location' => 'Burgos St., La Paz, Iloilo City',
        'type' => 'Campus Stop',
        'nearby' => 'Near SM City Iloilo'
    ],
    [
        'name' => 'ISAT-U Miagao Campus',
        'location' => 'Miagao, Iloilo',
        'type' => 'Campus Stop',
        'nearby' => 'Near Miagao Church'
    ],
    [
        'name' => 'ISAT-U Leon Campus',
        'location' => 'Leon, Iloilo',
        'type' => 'Campus Stop',
        'nearby' => 'Leon Town Center'
    ],
    [
        'name' => 'ISAT-U Dumangas Campus',
        'location' => 'Dumangas, Iloilo',
        'type' => 'Campus Stop',
        'nearby' => 'Dumangas Public Market'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Stop Locations - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">   
    <style>
        .terminal-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .terminal-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .terminal-header {
            background-color: #ffc107;
            color: #212529;
            padding: 15px;
            border-radius: 10px 10px 0 0;
        }
        
        .terminal-body {
            padding: 20px;
        }
        
        .facility-badge {
            background-color: #f8f9fa;
            color: #495057;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85rem;
            margin-right: 5px;
            margin-bottom: 5px;
            display: inline-block;
        }
        
        .route-badge {
            background-color: #e9ecef;
            color: #212529;
            padding: 8px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .campus-stop-card {
            background-color: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 0 8px 8px 0;
        }
        
        .location-info {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .location-info i {
            width: 24px;
            color: #ffc107;
            margin-right: 10px;
        }
        
        .map-placeholder {
            background-color: #e9ecef;
            height: 300px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .route-count-badge {
            background-color: #198754;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .terminal-icon {
            font-size: 2rem;
            color: #ffc107;
            margin-bottom: 10px;
        }
        
        .no-routes {
            color: #dc3545;
            font-style: italic;
        }
        
        .region-header {
            background-color: #343a40;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
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
                    <li class="nav-item">
                        <a class="nav-link active" href="locations.php">Locations</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="fares.php">Fares</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-map-marked-alt me-2"></i>Bus Stop Locations</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i> Find detailed information about all bus stops and terminals. View major terminals, ISAT-U campus stops, and route connections.
                        </div>
                        
                        <!-- Interactive Map Placeholder -->
                        <div class="map-placeholder mb-4">
                            <div class="text-center">
                                <i class="fas fa-map-marked-alt fa-3x mb-3"></i>
                                <h5>Interactive Map Coming Soon</h5>
                                <p>View all bus stops and terminals on an interactive map</p>
                            </div>
                        </div>
                        
                        <!-- Major Terminals Section by Region -->
                        <h5 class="mb-3"><i class="fas fa-building me-2"></i>Major Terminals</h5>
                        
                        <?php foreach ($terminals_by_region as $region => $region_terminals): ?>
                            <div class="region-header">
                                <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($region); ?></h5>
                            </div>
                            <div class="row mb-4">
                                <?php foreach ($region_terminals as $key => $terminal): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="terminal-card">
                                            <div class="terminal-header">
                                                <h5 class="mb-0">
                                                    <i class="fas fa-bus-alt me-2"></i>
                                                    <?php echo htmlspecialchars($terminal['name']); ?>
                                                </h5>
                                            </div>
                                            <div class="terminal-body">
                                                <div class="location-info">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars($terminal['address']); ?></span>
                                                </div>
                                                
                                                <div class="location-info">
                                                    <i class="fas fa-phone"></i>
                                                    <span><?php echo htmlspecialchars($terminal['contact']); ?></span>
                                                </div>
                                                
                                                <div class="location-info">
                                                    <i class="fas fa-clock"></i>
                                                    <span><?php echo htmlspecialchars($terminal['operating_hours']); ?></span>
                                                </div>
                                                
                                                <hr>
                                                
                                                <h6><i class="fas fa-concierge-bell me-2"></i>Facilities</h6>
                                                <div class="mb-3">
                                                    <?php foreach ($terminal['facilities'] as $facility): ?>
                                                        <span class="facility-badge">
                                                            <i class="fas fa-check-circle text-success me-1"></i>
                                                            <?php echo htmlspecialchars($facility); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <h6><i class="fas fa-route me-2"></i>Routes from this Terminal</h6>
                                                <?php 
                                                    $terminal_location = $key; 
                                                    $terminal_routes = [];
                                                    
                                                    if (isset($location_routes[$terminal_location])) {
                                                        $terminal_routes = $location_routes[$terminal_location];
                                                    } else {
                                                        // Try with different variations (with/without spaces)
                                                        foreach ($location_routes as $loc => $routes) {
                                                            if (strtolower(trim($loc)) == strtolower(trim($terminal_location))) {
                                                                $terminal_routes = $routes;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (count($terminal_routes) > 0):
                                                        $shown_routes = 0;
                                                        foreach ($terminal_routes as $route):
                                                            // Only show routes where this terminal is the origin (with trim)
                                                            if (strtolower(trim($route['origin'])) == strtolower(trim($terminal_location))):
                                                                $shown_routes++;
                                                ?>
                                                                <div class="route-badge">
                                                                    <span>
                                                                        <?php echo htmlspecialchars($route['origin'] . ' → ' . $route['destination']); ?>
                                                                    </span>
                                                                    <span>
                                                                        <span class="route-count-badge me-2">
                                                                            <?php echo $route['active_buses']; ?> buses
                                                                        </span>
                                                                    </span>
                                                                </div>
                                                <?php 
                                                            endif;
                                                        endforeach;
                                                        
                                                        if ($shown_routes == 0):
                                                            echo '<p class="no-routes">No active routes from this terminal</p>';
                                                        endif;
                                                    else:
                                                ?>
                                                        <p class="no-routes">No active routes from this terminal</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- ISAT-U Campus Stops Section -->
                        <h5 class="mb-3"><i class="fas fa-university me-2"></i>ISAT-U Campus Stops</h5>
                        <div class="row mb-4">
                            <?php foreach ($campus_stops as $stop): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="campus-stop-card">
                                        <h6 class="mb-2">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            <?php echo htmlspecialchars($stop['name']); ?>
                                        </h6>
                                        <div class="location-info">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($stop['location']); ?></span>
                                        </div>
                                        <div class="location-info">
                                            <i class="fas fa-info-circle"></i>
                                            <span><?php echo htmlspecialchars($stop['type']); ?></span>
                                        </div>
                                        <div class="location-info">
                                            <i class="fas fa-landmark"></i>
                                            <span>Near: <?php echo htmlspecialchars($stop['nearby']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- All Locations Section -->
                        <h5 class="mb-3"><i class="fas fa-list me-2"></i>All Service Locations</h5>
                        <div class="row">
                            <?php foreach ($locations as $location): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">
                                                <i class="fas fa-map-pin me-2"></i>
                                                <?php echo ucfirst(htmlspecialchars($location)); ?>
                                            </h6>
                                            <?php 
                                                $routes = $location_routes[$location] ?? [];
                                                $departures = 0;
                                                $arrivals = 0;
                                                
                                                foreach ($routes as $route) {
                                                    if ($route['origin'] == $location) $departures++;
                                                    if ($route['destination'] == $location) $arrivals++;
                                                }
                                            ?>
                                            <div class="d-flex justify-content-between small text-muted">
                                                <span><i class="fas fa-sign-out-alt me-1"></i> <?php echo $departures; ?> Departures</span>
                                                <span><i class="fas fa-sign-in-alt me-1"></i> <?php echo $arrivals; ?> Arrivals</span>
                                            </div>
                                            <hr>
                                            <div class="small">
                                                <strong>Connections:</strong><br>
                                                <?php 
                                                    $connections = [];
                                                    foreach ($routes as $route) {
                                                        if ($route['origin'] == $location) {
                                                            $connections[] = $route['destination'];
                                                        } elseif ($route['destination'] == $location) {
                                                            $connections[] = $route['origin'];
                                                        }
                                                    }
                                                    $connections = array_unique($connections);
                                                    
                                                    if (count($connections) > 0) {
                                                        echo implode(', ', array_map('ucfirst', $connections));
                                                    } else {
                                                        echo '<span class="text-muted">No active connections</span>';
                                                    }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Additional Information -->
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Travel Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-clock me-2"></i>Terminal Operating Hours</h6>
                                        <ul class="list-unstyled">
                                            <li>Major terminals: 3:00 AM - 10:00 PM</li>
                                            <li>ISAT-U campus stops: Based on class schedules</li>
                                            <li>Provincial terminals: May vary by location</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Important Notes</h6>
                                        <ul class="list-unstyled">
                                            <li>Arrive at least 30 minutes before departure</li>
                                            <li>Bring valid ID for ticket purchase</li>
                                            <li>Student ID required for student discount</li>
                                            <li>Check schedule for holiday operations</li>
                                        </ul>
                                    </div>
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
                        <li><a href="routes.php" class="text-white"><i class="fas fa-route me-2"></i>Routes</a></li>
                        <li><a href="schedule.php" class="text-white"><i class="fas fa-calendar-alt me-2"></i>Schedule</a></li>
                        <li><a href="booking.php" class="text-white"><i class="fas fa-ticket-alt me-2"></i>Book Ticket</a></li>
                        <li><a href="locations.php" class="text-white"><i class="fas fa-map-marked-alt me-2"></i>Locations</a></li>
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