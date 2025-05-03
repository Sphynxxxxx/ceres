<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

// Set default action
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle route creation/update
    if (isset($_POST['save_route'])) {
        $route_id = isset($_POST['route_id']) ? $_POST['route_id'] : null;
        $origin = $_POST['origin'];
        $destination = $_POST['destination'];
        $distance = $_POST['distance'];
        $estimated_duration = $_POST['estimated_duration'];
        
        // Calculate fare based on distance using fare calculator
        $fareRange = $fareCalculator->getFareRange($distance);
        $fare = $fareRange['regular'];
        
        try {
            if ($route_id) {
                // Update existing route
                $stmt = $conn->prepare("UPDATE routes SET origin = ?, destination = ?, distance = ?, estimated_duration = ?, fare = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("ssdsdi", $origin, $destination, $distance, $estimated_duration, $fare, $route_id);
                $stmt->execute();
                
                $success_message = "Route updated successfully!";
            } else {
                // Create new route
                $stmt = $conn->prepare("INSERT INTO routes (origin, destination, distance, estimated_duration, fare) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsd", $origin, $destination, $distance, $estimated_duration, $fare);
                $stmt->execute();
                
                $success_message = "Route created successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
    
    // Handle route deletion
    if (isset($_POST['delete_route'])) {
        $route_id = $_POST['route_id'];
        
        try {
            // Check if route is used by any buses
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM buses WHERE route_id = ?");
            $stmt->bind_param("i", $route_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                $error_message = "Route cannot be deleted because it is assigned to one or more buses.";
            } else {
                // Delete route
                $stmt = $conn->prepare("DELETE FROM routes WHERE id = ?");
                $stmt->bind_param("i", $route_id);
                $stmt->execute();
                
                $success_message = "Route deleted successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get route data if editing
$edit_route = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $route_id = $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM routes WHERE id = ?");
        $stmt->bind_param("i", $route_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $edit_route = $result->fetch_assoc();
        } else {
            $error_message = "Route not found.";
            $action = 'view';
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch all routes for display
$routes = [];
try {
    // Check if search term is present
    $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
    
    if (!empty($searchTerm)) {
        // Query with search filter
        $query = "SELECT r.*, 
                  (SELECT COUNT(*) FROM buses b WHERE LOWER(b.route_name) = CONCAT(LOWER(r.origin), ' → ', LOWER(r.destination))) as bus_count 
                  FROM routes r 
                  WHERE r.origin LIKE ? OR r.destination LIKE ? OR CONCAT(r.origin, ' → ', r.destination) LIKE ?
                  ORDER BY r.origin, r.destination";
        
        $stmt = $conn->prepare($query);
        $searchParam = "%" . $searchTerm . "%";
        $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        // Original query without search filter
        $query = "SELECT r.*, 
                  (SELECT COUNT(*) FROM buses b WHERE LOWER(b.route_name) = CONCAT(LOWER(r.origin), ' → ', LOWER(r.destination))) as bus_count 
                  FROM routes r 
                  ORDER BY r.origin, r.destination";
        $result = $conn->query($query);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate fare based on distance
            $fareRange = $fareCalculator->getFareRange($row['distance']);
            $row['calculated_regular_fare'] = $fareRange['regular'];
            $row['calculated_discounted_fare'] = $fareRange['discounted'];
            $routes[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error fetching routes data: " . $e->getMessage();
}

// Generate fare table for display
$fareTable = $fareCalculator->getFareTable(100, 10);

// Notification count for display in header (demo data)
$notification_count = 3;
$notifications = [
    ['message' => 'New booking received', 'time' => '5 minutes ago'],
    ['message' => 'Bus schedule updated', 'time' => '1 hour ago'],
    ['message' => 'New user registered', 'time' => '3 hours ago']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Routes Management - ISAT-U Ceres Bus Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/admin.css" rel="stylesheet">  
    <style>
        .card-table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
        .fare-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            background-color: #e9ecef;
            color: #495057;
        }
        .discount-info {
            color: #198754;
            font-size: 0.85rem;
        }
        .fare-table-container {
            max-height: 300px;
            overflow-y: auto;
        }
        .form-card, .fare-info-card {
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 20px;
        }
        .bus-count-badge {
            padding: 5px 10px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .fare-update-info {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            color: #6c757d;
        }
        .update-message {
            display: none;
            margin-top: 10px;
        }
        .card1 {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header d-flex align-items-center">
                <i class="fas fa-bus-alt me-2 fs-4"></i>
                <h4 class="mb-0">Admin Booking System</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../../admin.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="bookings_admin.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="routes_admin.php">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="schedules_admin.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="buses_admin.php">
                        <i class="fas fa-bus"></i>
                        <span>Buses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users_admin.php">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="payments_admin.php">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inquiries.php">
                        <i class="fas fa-envelope"></i>
                        <span>Inquiries</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Page Content -->
        <div class="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg navbar-light mb-4">
                <div class="container-fluid">
                    <button id="sidebarToggle" class="btn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <form class="d-flex ms-auto" action="routes_admin.php" method="get">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" placeholder="Search routes" aria-label="Search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-route me-2"></i>Route Management</h2>
                    <div>
                        <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                            <a href="routes_admin.php" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-times me-1"></i> Clear Search
                            </a>
                        <?php endif; ?>
                        <?php if ($action === 'view'): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRouteModal">
                            <i class="fas fa-plus me-2"></i>Add New Route
                        </button>
                        <?php else: ?>
                        <a href="routes_admin.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Routes
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Fare Information Card -->
                    <div class="col-md-4 mb-4">
                        <div class="card1">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Fare Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>Bus fares are calculated automatically based on distance.
                                </div>
                                
                                <h6 class="mb-3">Fare Calculation Rules:</h6>
                                <ul class="list-group mb-3">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Base fare (first 4km)
                                        <span class="badge bg-primary rounded-pill">₱12.00</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Additional per km
                                        <span class="badge bg-primary rounded-pill">₱1.80</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Student/Senior/PWD Discount
                                        <span class="badge bg-success rounded-pill">20%</span>
                                    </li>
                                </ul>
                                
                                <h6 class="mb-2">Sample Fare Table:</h6>
                                <div class="fare-table-container">
                                    <table class="table table-sm table-striped table-bordered">
                                        <thead class="table-warning">
                                            <tr>
                                                <th>Distance</th>
                                                <th>Regular</th>
                                                <th>Discounted</th>
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
                            </div>
                        </div>
                    </div>
                    
                    <!-- Routes Table -->
                    <div class="col-md-8">
                        <div class="card1">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">All Routes</h5>
                                    <span class="badge bg-primary"><?php echo count($routes); ?> routes found</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (count($routes) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Route</th>
                                                <th>Distance</th>
                                                <th>Est. Time</th>
                                                <th>Regular Fare</th>
                                                <th>Discounted</th>
                                                <th>Buses</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($routes as $route): ?>
                                            <tr>
                                                <td>#<?php echo $route['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo ucfirst(htmlspecialchars($route['origin'])); ?> → <?php echo ucfirst(htmlspecialchars($route['destination'])); ?></div>
                                                </td>
                                                <td><?php echo $route['distance']; ?> km</td>
                                                <td><?php echo htmlspecialchars($route['estimated_duration']); ?></td>
                                                <td>₱<?php echo number_format($route['calculated_regular_fare'], 2); ?></td>
                                                <td>₱<?php echo number_format($route['calculated_discounted_fare'], 2); ?></td>
                                                <td>
                                                    <?php if ($route['bus_count'] > 0): ?>
                                                    <span class="badge bg-success"><?php echo $route['bus_count']; ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-outline-primary btn-sm edit-route" 
                                                                data-id="<?php echo $route['id']; ?>"
                                                                data-bs-toggle="modal" data-bs-target="#editRouteModal" 
                                                                data-bs-origin="<?php echo htmlspecialchars($route['origin']); ?>"
                                                                data-bs-destination="<?php echo htmlspecialchars($route['destination']); ?>"
                                                                data-bs-distance="<?php echo $route['distance']; ?>"
                                                                data-bs-duration="<?php echo htmlspecialchars($route['estimated_duration']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger btn-sm delete-route" 
                                                                data-id="<?php echo $route['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($route['origin'] . ' to ' . $route['destination']); ?>"
                                                                data-bs-toggle="modal" data-bs-target="#deleteRouteModal">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>No routes found. Add your first route using the "Add New Route" button.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Route Modal -->
    <div class="modal fade" id="addRouteModal" tabindex="-1" aria-labelledby="addRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addRouteModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addRouteForm" method="post" action="routes_admin.php">
                        <div class="mb-3">
                            <label for="origin" class="form-label">Origin <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="origin" name="origin" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="destination" class="form-label">Destination <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="destination" name="destination" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="distance" class="form-label">Distance (km) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="distance" name="distance" step="0.01" min="0" required
                                   oninput="calculateFare(this.value)">
                            <div class="form-text">The fare will be calculated automatically based on distance.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="estimated_duration" class="form-label">Estimated Travel Time <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="estimated_duration" name="estimated_duration" required
                                   placeholder="e.g. 2h 30m">
                        </div>
                        
                        <div class="mb-3">
                            <label for="calculated_fare" class="form-label">Calculated Fare</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="text" class="form-control" id="calculated_fare" readonly value="0.00">
                            </div>
                            <div id="fare_info" class="form-text discount-info"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addRouteForm" name="save_route" class="btn btn-primary">Save Route</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Route Modal -->
    <div class="modal fade" id="editRouteModal" tabindex="-1" aria-labelledby="editRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRouteModalLabel"><i class="fas fa-edit me-2"></i>Edit Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editRouteForm" method="post" action="routes_admin.php">
                        <input type="hidden" id="edit_route_id" name="route_id">
                        
                        <div class="mb-3">
                            <label for="edit_origin" class="form-label">Origin <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_origin" name="origin" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_destination" class="form-label">Destination <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_destination" name="destination" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_distance" class="form-label">Distance (km) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_distance" name="distance" step="0.01" min="0" required
                                   oninput="calculateEditFare(this.value)">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_estimated_duration" class="form-label">Estimated Travel Time <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_estimated_duration" name="estimated_duration" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_calculated_fare" class="form-label">Calculated Fare</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="text" class="form-control" id="edit_calculated_fare" readonly>
                            </div>
                            <div id="edit_fare_info" class="form-text discount-info"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editRouteForm" name="save_route" class="btn btn-primary">Update Route</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Route Modal -->
    <div class="modal fade" id="deleteRouteModal" tabindex="-1" aria-labelledby="deleteRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteRouteModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the route:</p>
                    <p class="fw-bold" id="route_name_display"></p>
                    <p class="text-danger">This action cannot be undone and may affect buses assigned to this route.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="routes_admin.php" id="deleteRouteForm">
                        <input type="hidden" name="route_id" id="delete_route_id">
                        <button type="submit" name="delete_route" class="btn btn-danger">Delete Route</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.body.classList.toggle('collapsed-sidebar');
        });

        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Function to calculate fare based on distance
        function calculateFare(distance) {
            if (distance === '' || isNaN(distance)) {
                document.getElementById('calculated_fare').value = '0.00';
                document.getElementById('fare_info').innerHTML = '';
                return;
            }
            
            // Convert to number
            distance = parseFloat(distance);
            
            // Calculate base fare (first 4km)
            let fare = 12.00;
            
            // Add fare for additional distance beyond 4km
            if (distance > 4) {
                let additionalDistance = distance - 4;
                fare += additionalDistance * 1.80;
            }
            
            // Round fare to nearest 0.25 peso
            fare = Math.round(fare * 4) / 4;
            
            // Calculate discounted fare
            let discountedFare = fare * 0.8; // 20% discount
            discountedFare = Math.round(discountedFare * 4) / 4; // Round to nearest 0.25
            
            // Display calculated fare
            document.getElementById('calculated_fare').value = fare.toFixed(2);
            document.getElementById('fare_info').innerHTML = `Discounted fare (20%): ₱${discountedFare.toFixed(2)}`;
        }
        
        // Function to calculate fare for edit form
        function calculateEditFare(distance) {
            if (distance === '' || isNaN(distance)) {
                document.getElementById('edit_calculated_fare').value = '0.00';
                document.getElementById('edit_fare_info').innerHTML = '';
                return;
            }
            
            // Convert to number
            distance = parseFloat(distance);
            
            // Calculate base fare (first 4km)
            let fare = 12.00;
            
            // Add fare for additional distance beyond 4km
            if (distance > 4) {
                let additionalDistance = distance - 4;
                fare += additionalDistance * 1.80;
            }
            
            // Round fare to nearest 0.25 peso
            fare = Math.round(fare * 4) / 4;
            
            // Calculate discounted fare
            let discountedFare = fare * 0.8; // 20% discount
            discountedFare = Math.round(discountedFare * 4) / 4; // Round to nearest 0.25
            
            // Display calculated fare
            document.getElementById('edit_calculated_fare').value = fare.toFixed(2);
            document.getElementById('edit_fare_info').innerHTML = `Discounted fare (20%): ₱${discountedFare.toFixed(2)}`;
        }
        
        // Edit route modal
        document.querySelectorAll('.edit-route').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const origin = this.getAttribute('data-bs-origin');
                const destination = this.getAttribute('data-bs-destination');
                const distance = this.getAttribute('data-bs-distance');
                const duration = this.getAttribute('data-bs-duration');
                
                document.getElementById('edit_route_id').value = id;
                document.getElementById('edit_origin').value = origin;
                document.getElementById('edit_destination').value = destination;
                document.getElementById('edit_distance').value = distance;
                document.getElementById('edit_estimated_duration').value = duration;
                
                // Calculate fare based on distance
                calculateEditFare(distance);
            });
        });
        
        // Delete route modal
        document.querySelectorAll('.delete-route').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('delete_route_id').value = id;
                document.getElementById('route_name_display').textContent = name;
            });
        });
    </script>
</body>
</html>