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



// Handle schedule creation or update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    try {
        // Check if this is an update (edit) operation
        $isUpdate = isset($_POST['schedule_id']) && !empty($_POST['schedule_id']);
        
        // Get form data
        $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
        $origin = isset($_POST['origin']) ? trim($_POST['origin']) : '';
        $destination = isset($_POST['destination']) ? trim($_POST['destination']) : '';
        $departure_time = isset($_POST['departure_time']) ? $_POST['departure_time'] : '';
        $arrival_time = isset($_POST['arrival_time']) ? $_POST['arrival_time'] : '';
        $fare_amount = isset($_POST['fare_amount']) ? floatval($_POST['fare_amount']) : 0;
        $trip_number = isset($_POST['trip_number']) ? trim($_POST['trip_number']) : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'active';
        $recurring = isset($_POST['recurring']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if ($bus_id <= 0) {
            $errors[] = "Please select a valid bus";
        }
        if (empty($origin)) {
            $errors[] = "Origin is required";
        }
        if (empty($destination)) {
            $errors[] = "Destination is required";
        }
        if (empty($departure_time)) {
            $errors[] = "Departure time is required";
        }
        if (empty($arrival_time)) {
            $errors[] = "Arrival time is required";
        }
        if ($fare_amount <= 0) {
            $errors[] = "Fare amount must be greater than zero";
        }
        
        if (empty($errors)) {
            if ($isUpdate) {
                // Update existing schedule
                $schedule_id = intval($_POST['schedule_id']);
                
                $query = "UPDATE schedules SET 
                            bus_id = ?,
                            origin = ?,
                            destination = ?,
                            departure_time = ?,
                            arrival_time = ?,
                            fare_amount = ?,
                            trip_number = ?,
                            status = ?,
                            recurring = ?,
                            updated_at = NOW()
                          WHERE id = ?";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("issssdssii", $bus_id, $origin, $destination, $departure_time, $arrival_time, 
                                 $fare_amount, $trip_number, $status, $recurring, $schedule_id);
                
                if ($stmt->execute()) {
                    $success_message = "Schedule updated successfully!";
                } else {
                    $error_message = "Error updating schedule: " . $stmt->error;
                }
            } else {
                // Insert new schedule
                $query = "INSERT INTO schedules (bus_id, origin, destination, departure_time, arrival_time, 
                                               fare_amount, trip_number, status, recurring) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($query);
                // Correct parameter type string - needs 9 type indicators for 9 parameters
                $stmt->bind_param("issssdssi", $bus_id, $origin, $destination, $departure_time, $arrival_time, 
                                 $fare_amount, $trip_number, $status, $recurring);
                
                if ($stmt->execute()) {
                    $success_message = "New schedule created successfully!";
                } else {
                    $error_message = "Error creating schedule: " . $stmt->error;
                }
            }
        } else {
            $error_message = "Please fix the following errors: " . implode(", ", $errors);
        }
    } catch (Exception $e) {
        $error_message = "Error processing schedule: " . $e->getMessage();
    }
}



// Handle schedule deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    try {
        $schedule_id = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
        
        if ($schedule_id <= 0) {
            $error_message = "Invalid schedule ID";
        } else {
            // Check if bookings table exists
            $tableExists = $conn->query("SHOW TABLES LIKE 'bookings'");
            $bookings_table_exists = $tableExists && $tableExists->num_rows > 0;
            
            // Check if the schedule has any bookings
            if ($bookings_table_exists) {
                $check_query = "SELECT COUNT(*) as booking_count FROM bookings WHERE bus_id IN (SELECT bus_id FROM schedules WHERE id = ?)";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("i", $schedule_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $booking_count = $check_result->fetch_assoc()['booking_count'];
                
                if ($booking_count > 0) {
                    $error_message = "Cannot delete schedule with active bookings. Please cancel all bookings first.";
                } else {
                    // Delete the schedule
                    $delete_query = "DELETE FROM schedules WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("i", $schedule_id);
                    
                    if ($delete_stmt->execute()) {
                        $success_message = "Schedule deleted successfully!";
                    } else {
                        $error_message = "Error deleting schedule: " . $delete_stmt->error;
                    }
                }
            } else {
                // No bookings table, so can delete directly
                $delete_query = "DELETE FROM schedules WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $schedule_id);
                
                if ($delete_stmt->execute()) {
                    $success_message = "Schedule deleted successfully!";
                } else {
                    $error_message = "Error deleting schedule: " . $delete_stmt->error;
                }
            }
        }
    } catch (Exception $e) {
        $error_message = "Error deleting schedule: " . $e->getMessage();
    }
}

// Check if bookings table exists
$tableExists = $conn->query("SHOW TABLES LIKE 'bookings'");
$bookings_table_exists = $tableExists && $tableExists->num_rows > 0;

// Selected date for filtering
$selected_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');

// Fetch all schedules for display
$schedules = [];
try {
    // Apply filters if set
    $whereConditions = [];
    $whereParams = [];
    $paramTypes = "";
    
    // Filter by origin
    if (isset($_GET['filter_origin']) && !empty($_GET['filter_origin'])) {
        $whereConditions[] = "s.origin LIKE ?";
        $whereParams[] = "%" . $_GET['filter_origin'] . "%";
        $paramTypes .= "s";
    }
    
    // Filter by destination
    if (isset($_GET['filter_destination']) && !empty($_GET['filter_destination'])) {
        $whereConditions[] = "s.destination LIKE ?";
        $whereParams[] = "%" . $_GET['filter_destination'] . "%";
        $paramTypes .= "s";
    }
    
    // Filter by status
    if (isset($_GET['filter_status']) && !empty($_GET['filter_status'])) {
        $whereConditions[] = "s.status = ?";
        $whereParams[] = $_GET['filter_status'];
        $paramTypes .= "s";
    }
    
    // Filter by trip number
    if (isset($_GET['filter_trip']) && !empty($_GET['filter_trip'])) {
        $whereConditions[] = "s.trip_number = ?";
        $whereParams[] = $_GET['filter_trip'];
        $paramTypes .= "s";
    }
    
    // Base query with booking count
    if ($bookings_table_exists) {
        $baseQuery = "SELECT s.*, 
                     b.plate_number, 
                     b.bus_type, 
                     (SELECT COUNT(*) FROM bookings WHERE bus_id = s.bus_id AND DATE(booking_date) = ?) as booking_count
                     FROM schedules s 
                     JOIN buses b ON s.bus_id = b.id";
        
        // Prepare parameters
        array_unshift($whereParams, $selected_date);
        $paramTypes = "s" . $paramTypes;
    } else {
        // Bookings table doesn't exist, skip the booking count
        $baseQuery = "SELECT s.*, 
                     b.plate_number, 
                     b.bus_type, 
                     0 as booking_count
                     FROM schedules s 
                     JOIN buses b ON s.bus_id = b.id";
    }
    
    // Add WHERE clause if filters were applied
    if (!empty($whereConditions)) {
        $baseQuery .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    // Add ORDER BY clause
    $baseQuery .= " ORDER BY s.departure_time, s.origin, s.destination";
    
    // Prepare and execute the query
    if ($bookings_table_exists && !empty($whereParams)) {
        $stmt = $conn->prepare($baseQuery);
        $stmt->bind_param($paramTypes, ...$whereParams);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($baseQuery);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $schedules[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error fetching schedules data: " . $e->getMessage();
}

// Fetch all buses for the form dropdown
$buses = [];
try {
    $query = "SELECT id, plate_number, bus_type, route_name FROM buses WHERE status = 'Active' ORDER BY plate_number";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $buses[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error fetching buses data: " . $e->getMessage();
}

// Fetch all routes for the form dropdown
$routes = [];
try {
    $query = "SELECT origin, destination, distance, estimated_duration FROM routes ORDER BY origin, destination";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $routes[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error fetching routes data: " . $e->getMessage();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - ISAT-U Ceres Bus Admin</title>
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
        .bus-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            background-color: #e9ecef;
            color: #495057;
        }
        .schedule-status {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 0.85rem;
        }
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-completed {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .card1 {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .booking-count-badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.75rem;
            background-color: #e2e3e5;
            color: #383d41;
        }
        .time-badge {
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 600;
            background-color: #f8f9fa;
        }
        .time-arrow {
            margin: 0 8px;
            color: #6c757d;
        }
        .filter-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .schedule-date {
            font-size: 0.85rem;
            color: #6c757d;
        }
        /* New styles for the auto-calculate feature */
        .is-valid {
            border-color: #28a745 !important;
            background-color: #d4edda !important;
            transition: all 0.3s;
        }
        .duration-info {
            font-size: 0.85rem;
            color: #6c757d;
            font-style: italic;
            margin-top: 3px;
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
                    <a class="nav-link" href="routes_admin.php">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="schedules_admin.php">
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
                    <a class="nav-link" href="announcements_admin.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
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
                        <form class="d-flex ms-auto">
                            <div class="input-group">
                                <input class="form-control" type="search" placeholder="Search schedules" aria-label="Search">
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
                    <h2><i class="fas fa-calendar-alt me-2"></i>Schedule Management</h2>
                    <div>
                        <?php if (count($schedules) > 0 && !empty($_GET['filter_origin']) || !empty($_GET['filter_destination']) || !empty($_GET['filter_status']) || !empty($_GET['filter_trip'])): ?>
                            <a href="schedules_admin.php" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-times me-1"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                        <?php if ($action === 'view'): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                                <i class="fas fa-plus me-2"></i>Add New Schedule
                            </button>
                        <?php else: ?>
                            <a href="schedules_admin.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Schedules
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
                
                <!-- Filter Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="filter-card">
                            <form class="row g-3" action="schedules_admin.php" method="get">
                                <div class="col-md-3">
                                    <label for="filter_origin" class="form-label">Origin</label>
                                    <input type="text" class="form-control" id="filter_origin" name="filter_origin" value="<?php echo isset($_GET['filter_origin']) ? htmlspecialchars($_GET['filter_origin']) : ''; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="filter_destination" class="form-label">Destination</label>
                                    <input type="text" class="form-control" id="filter_destination" name="filter_destination" value="<?php echo isset($_GET['filter_destination']) ? htmlspecialchars($_GET['filter_destination']) : ''; ?>">
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_date" class="form-label">Date</label>
                                    <input type="date" class="form-control" id="filter_date" name="filter_date">
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_status" class="form-label">Status</label>
                                    <select class="form-select" id="filter_status" name="filter_status">
                                        <option value="">All Statuses</option>
                                        <option value="active" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="completed" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_trip" class="form-label">Trip #</label>
                                    <select class="form-select" id="filter_trip" name="filter_trip">
                                        <option value="">All Trips</option>
                                        <option value="1st Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === '1st Trip') ? 'selected' : ''; ?>>1st Trip</option>
                                        <option value="2nd Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === '2nd Trip') ? 'selected' : ''; ?>>2nd Trip</option>
                                        <option value="3rd Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === '3rd Trip') ? 'selected' : ''; ?>>3rd Trip</option>
                                        <option value="4th Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === '4th Trip') ? 'selected' : ''; ?>>4th Trip</option>
                                        <option value="5th Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === '5th Trip') ? 'selected' : ''; ?>>5th Trip</option>
                                        <option value="Special Trip" <?php echo (isset($_GET['filter_trip']) && $_GET['filter_trip'] === 'Special Trip') ? 'selected' : ''; ?>>Special Trip</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-2"></i>Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Quick Stats -->
                    <div class="col-md-4 mb-4">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h5 class="text-primary"><i class="fas fa-calendar-check mb-2"></i></h5>
                                        <h3 class="mb-0"><?php echo count($schedules); ?></h3>
                                        <p class="text-muted mb-0">Total Schedules</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h5 class="text-success"><i class="fas fa-bus mb-2"></i></h5>
                                        <h3 class="mb-0"><?php echo count($buses); ?></h3>
                                        <p class="text-muted mb-0">Active Buses</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="card1">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Schedule Information</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-info">
                                            <i class="fas fa-lightbulb me-2"></i>Schedules are linked to specific buses and routes.
                                        </div>
                                        
                                        <h6 class="mb-3">Schedule Status Types:</h6>
                                        <ul class="list-group mb-3">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Active
                                                <span class="badge bg-success rounded-pill">Available for booking</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Inactive
                                                <span class="badge bg-danger rounded-pill">Not available</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Completed
                                                <span class="badge bg-info rounded-pill">Trip finished</span>
                                            </li>
                                        </ul>
                                        
                                        <h6 class="mb-3">Trip Numbers:</h6>
                                        <ul class="list-group mb-3">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                1st Trip, 2nd Trip, etc.
                                                <span class="badge bg-info rounded-pill">Sequence ID</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Special Trip
                                                <span class="badge bg-warning rounded-pill">Non-regular trip</span>
                                            </li>
                                        </ul>
                                        
                                        <h6 class="mb-2">Trip Types:</h6>
                                        <ul class="list-group">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                One-time Trip
                                                <span class="badge bg-secondary rounded-pill">Single occurrence</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Recurring Trip
                                                <span class="badge bg-primary rounded-pill">Daily schedule</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Schedules Table -->
                    <div class="col-md-8">
                        <div class="card1">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">All Schedules</h5>
                                    <div>
                                        <?php if (!empty($_GET['filter_origin']) || !empty($_GET['filter_destination']) || !empty($_GET['filter_status']) || !empty($_GET['filter_trip'])): ?>
                                            <span class="badge bg-info me-2">Filtered Results</span>
                                        <?php endif; ?>
                                        <span class="badge bg-primary"><?php echo count($schedules); ?> schedules found</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (count($schedules) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Route</th>
                                                <th>Time</th>
                                                <th>Bus</th>
                                                <th>Trip #</th>
                                                <th>Fare</th>
                                                <th>Status</th>
                                                <th>Bookings</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($schedules as $schedule): ?>
                                            <tr>
                                                <td>#<?php echo $schedule['id']; ?></td>
                                                <td>
                                                    <div class="fw-bold"><?php echo ucfirst(htmlspecialchars($schedule['origin'])); ?> → <?php echo ucfirst(htmlspecialchars($schedule['destination'])); ?></div>
                                                    <?php if ($schedule['recurring']): ?>
                                                    <span class="badge bg-primary">Daily</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">One-time</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="time-badge"><?php echo date('h:i A', strtotime($schedule['departure_time'])); ?></span>
                                                    <span class="time-arrow"><i class="fas fa-arrow-right"></i></span>
                                                    <span class="time-badge"><?php echo date('h:i A', strtotime($schedule['arrival_time'])); ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($schedule['plate_number']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($schedule['bus_type']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($schedule['trip_number'])): ?>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($schedule['trip_number']); ?></span>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>₱<?php echo number_format($schedule['fare_amount'], 2); ?></td>
                                                <td>
                                                    <?php if ($schedule['status'] === 'active'): ?>
                                                    <span class="schedule-status status-active"><?php echo ucfirst($schedule['status']); ?></span>
                                                    <?php elseif ($schedule['status'] === 'inactive'): ?>
                                                    <span class="schedule-status status-inactive"><?php echo ucfirst($schedule['status']); ?></span>
                                                    <?php else: ?>
                                                    <span class="schedule-status status-completed"><?php echo ucfirst($schedule['status']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="booking-count-badge">
                                                        <i class="fas fa-users me-1"></i> 
                                                        <?php echo $schedule['booking_count']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-outline-primary btn-sm edit-schedule" 
                                                                data-id="<?php echo $schedule['id']; ?>"
                                                                data-bs-toggle="modal" data-bs-target="#editScheduleModal">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger btn-sm delete-schedule" 
                                                                data-id="<?php echo $schedule['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($schedule['origin'] . ' to ' . $schedule['destination']); ?>"
                                                                data-bs-toggle="modal" data-bs-target="#deleteScheduleModal">
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
                                    <i class="fas fa-info-circle me-2"></i>No schedules found. Add your first schedule using the "Add New Schedule" button.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Schedule Modal -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1" aria-labelledby="addScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addScheduleModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addScheduleForm" method="post" action="schedules_admin.php">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="bus_id" class="form-label">Select Bus <span class="text-danger">*</span></label>
                                <select class="form-select" id="bus_id" name="bus_id" required>
                                    <option value="">Select a bus</option>
                                    <?php foreach ($buses as $bus): ?>
                                    <option value="<?php echo $bus['id']; ?>" data-route="<?php echo htmlspecialchars($bus['route_name']); ?>">
                                        <?php echo htmlspecialchars($bus['plate_number']); ?> - <?php echo htmlspecialchars($bus['bus_type']); ?> 
                                        (<?php echo htmlspecialchars($bus['route_name']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Selecting a bus will automatically fill the route information.</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="origin" class="form-label">Origin <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="origin" name="origin" required>
                            </div>
                            <div class="col-md-6">
                                <label for="destination" class="form-label">Destination <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="destination" name="destination" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="departure_time" class="form-label">Departure Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="departure_time" name="departure_time" required>
                            </div>
                            <div class="col-md-6">
                                <label for="arrival_time" class="form-label">Arrival Time <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="time" class="form-control" id="arrival_time" name="arrival_time" required>
                                    <button class="btn btn-outline-secondary" type="button" id="calculate_arrival">
                                        <i class="fas fa-calculator"></i> Auto
                                    </button>
                                </div>
                                <div class="form-text">This will be calculated automatically based on route duration.</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fare_amount" class="form-label">Fare Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="fare_amount" name="fare_amount" step="0.01" min="0" required>
                                <div class="form-text">Regular fare amount (non-discounted).</div>
                            </div>
                            <div class="col-md-6">
                                <label for="trip_number" class="form-label">Trip Number</label>
                                <select class="form-select" id="trip_number" name="trip_number">
                                    <option value="">-- Select Trip Number --</option>
                                    <option value="1st Trip">1st Trip</option>
                                    <option value="2nd Trip">2nd Trip</option>
                                    <option value="3rd Trip">3rd Trip</option>
                                    <option value="4th Trip">4th Trip</option>
                                    <option value="5th Trip">5th Trip</option>
                                    <option value="Special Trip">Special Trip</option>
                                </select>
                                <div class="form-text">Designate this as a specific trip number.</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input type="checkbox" class="form-check-input" id="recurring" name="recurring" value="1">
                                    <label class="form-check-label" for="recurring">Daily Recurring Trip</label>
                                    <div class="form-text">Check if this schedule repeats every day.</div>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="save_schedule" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addScheduleForm" class="btn btn-primary">Save Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div class="modal fade" id="editScheduleModal" tabindex="-1" aria-labelledby="editScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editScheduleModalLabel"><i class="fas fa-edit me-2"></i>Edit Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editScheduleForm" method="post" action="schedules_admin.php">
                        <input type="hidden" id="edit_schedule_id" name="schedule_id">
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="edit_bus_id" class="form-label">Select Bus <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_bus_id" name="bus_id" required>
                                    <option value="">Select a bus</option>
                                    <?php foreach ($buses as $bus): ?>
                                    <option value="<?php echo $bus['id']; ?>" data-route="<?php echo htmlspecialchars($bus['route_name']); ?>">
                                        <?php echo htmlspecialchars($bus['plate_number']); ?> - <?php echo htmlspecialchars($bus['bus_type']); ?> 
                                        (<?php echo htmlspecialchars($bus['route_name']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_origin" class="form-label">Origin <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_origin" name="origin" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_destination" class="form-label">Destination <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_destination" name="destination" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_departure_time" class="form-label">Departure Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="edit_departure_time" name="departure_time" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_arrival_time" class="form-label">Arrival Time <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="time" class="form-control" id="edit_arrival_time" name="arrival_time" required>
                                    <button class="btn btn-outline-secondary" type="button" id="edit_calculate_arrival">
                                        <i class="fas fa-calculator"></i> Auto
                                    </button>
                                </div>
                                <div class="form-text">This will be calculated automatically based on route duration.</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_fare_amount" class="form-label">Fare Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="edit_fare_amount" name="fare_amount" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_trip_number" class="form-label">Trip Number</label>
                                <select class="form-select" id="edit_trip_number" name="trip_number">
                                    <option value="">-- Select Trip Number --</option>
                                    <option value="1st Trip">1st Trip</option>
                                    <option value="2nd Trip">2nd Trip</option>
                                    <option value="3rd Trip">3rd Trip</option>
                                    <option value="4th Trip">4th Trip</option>
                                    <option value="5th Trip">5th Trip</option>
                                    <option value="Special Trip">Special Trip</option>
                                </select>
                                <div class="form-text">Designate this as a specific trip number.</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input type="checkbox" class="form-check-input" id="edit_recurring" name="recurring" value="1">
                                    <label class="form-check-label" for="edit_recurring">Daily Recurring Trip</label>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="save_schedule" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editScheduleForm" class="btn btn-primary">Update Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Schedule Confirmation Modal -->
    <div class="modal fade" id="deleteScheduleModal" tabindex="-1" aria-labelledby="deleteScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteScheduleModalLabel"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the schedule for <span id="delete-schedule-name" class="fw-bold"></span>?</p>
                    <p class="text-danger"><i class="fas fa-info-circle me-2"></i>This action cannot be undone. If the schedule has active bookings, you will not be able to delete it.</p>
                    <form id="deleteScheduleForm" method="post" action="schedules_admin.php">
                        <input type="hidden" id="delete_schedule_id" name="schedule_id">
                        <input type="hidden" name="delete_schedule" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteScheduleForm" class="btn btn-danger">Delete Schedule</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap and jQuery Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-fill arrival time on departure time change
            document.getElementById('departure_time').addEventListener('change', function() {
                const origin = document.getElementById('origin').value;
                const destination = document.getElementById('destination').value;
                const departureTime = this.value;
                
                if (origin && destination && departureTime) {
                    fetchRouteDuration(origin, destination, departureTime);
                }
            });
            
            // Calculate arrival time button
            document.getElementById('calculate_arrival').addEventListener('click', function() {
                const origin = document.getElementById('origin').value;
                const destination = document.getElementById('destination').value;
                const departureTime = document.getElementById('departure_time').value;
                
                if (!origin || !destination) {
                    alert('Please select origin and destination first');
                    return;
                }
                
                if (!departureTime) {
                    alert('Please enter departure time first');
                    return;
                }
                
                fetchRouteDuration(origin, destination, departureTime);
            });
            
            // Calculate arrival time button for edit form
            document.getElementById('edit_calculate_arrival').addEventListener('click', function() {
                const origin = document.getElementById('edit_origin').value;
                const destination = document.getElementById('edit_destination').value;
                const departureTime = document.getElementById('edit_departure_time').value;
                
                if (!origin || !destination) {
                    alert('Please select origin and destination first');
                    return;
                }
                
                if (!departureTime) {
                    alert('Please enter departure time first');
                    return;
                }
                
                // Reuse the fetchRouteDuration function but for edit form
                const routes = <?php echo json_encode($routes); ?>;
                
                for (const route of routes) {
                    if (route.origin.toLowerCase() === origin.toLowerCase() && 
                        route.destination.toLowerCase() === destination.toLowerCase()) {
                        
                        // Parse duration in format "2h 30m" or "1h"
                        const durationParts = route.estimated_duration.split('h');
                        const hours = parseInt(durationParts[0].trim());
                        // Check if minutes part exists
                        const minutesPart = durationParts.length > 1 ? durationParts[1].trim() : "0";
                        // Extract numeric part from minutes (remove 'm' if present)
                        const minutes = parseInt(minutesPart.replace('m', '')) || 0;
                        const totalMinutes = (hours * 60) + minutes;
                        
                        // Calculate arrival time
                        const depTime = new Date(`2023-01-01T${departureTime}`);
                        depTime.setMinutes(depTime.getMinutes() + totalMinutes);
                        
                        const arrivalHours = depTime.getHours().toString().padStart(2, '0');
                        const arrivalMinutes = depTime.getMinutes().toString().padStart(2, '0');
                        
                        document.getElementById('edit_arrival_time').value = `${arrivalHours}:${arrivalMinutes}`;
                        
                        // Show success message
                        const arrivalField = document.getElementById('edit_arrival_time');
                        arrivalField.classList.add('is-valid');
                        setTimeout(() => {
                            arrivalField.classList.remove('is-valid');
                        }, 3000);
                        
                        return;
                    }
                }
                
                alert('No matching route found for the selected origin and destination.');
            });
        });
        
        // Auto-fill origin and destination based on bus selection
        document.getElementById('bus_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const routeName = selectedOption.getAttribute('data-route');
                if (routeName) {
                    const routeParts = routeName.split(' → ');
                    if (routeParts.length === 2) {
                        document.getElementById('origin').value = routeParts[0];
                        document.getElementById('destination').value = routeParts[1];
                        
                        // Fetch fare amount for this route
                        fetchRouteFare(routeParts[0], routeParts[1]);
                    }
                }
            }
        });
        
        // Auto-fill arrival time based on departure time and route
        document.getElementById('departure_time').addEventListener('change', function() {
            const origin = document.getElementById('origin').value;
            const destination = document.getElementById('destination').value;
            const departureTime = this.value;
            
            if (origin && destination && departureTime) {
                fetchRouteDuration(origin, destination, departureTime);
            }
        });
        
        // Fetch route fare from routes table
        function fetchRouteFare(origin, destination) {
            // In a real application, this would be an AJAX call to a backend endpoint
            // For demo purposes, we're using predefined routes array
            const routes = <?php echo json_encode($routes); ?>;
            
            for (const route of routes) {
                if (route.origin.toLowerCase() === origin.toLowerCase() && 
                    route.destination.toLowerCase() === destination.toLowerCase()) {
                    // Calculate fare based on distance
                    const distance = parseFloat(route.distance);
                    const baseFare = 12;
                    const ratePerKm = 1.8;
                    
                    let fare = baseFare;
                    if (distance > 4) {
                        fare += (distance - 4) * ratePerKm;
                    }
                    
                    // Round to nearest 0.25
                    fare = Math.ceil(fare * 4) / 4;
                    
                    document.getElementById('fare_amount').value = fare.toFixed(2);
                    return;
                }
            }
            
            // If no matching route found
            document.getElementById('fare_amount').value = '';
        }
        
        // Calculate arrival time based on departure time and route duration
        function fetchRouteDuration(origin, destination, departureTime) {
            // Use routes data from PHP
            const routes = <?php echo json_encode($routes); ?>;
            
            for (const route of routes) {
                if (route.origin.toLowerCase() === origin.toLowerCase() && 
                    route.destination.toLowerCase() === destination.toLowerCase()) {
                    
                    // Parse duration in format "2h 30m" or "1h"
                    const durationParts = route.estimated_duration.split('h');
                    const hours = parseInt(durationParts[0].trim());
                    // Check if minutes part exists
                    const minutesPart = durationParts.length > 1 ? durationParts[1].trim() : "0";
                    // Extract numeric part from minutes (remove 'm' if present)
                    const minutes = parseInt(minutesPart.replace('m', '')) || 0;
                    const totalMinutes = (hours * 60) + minutes;
                    
                    // Calculate arrival time
                    const depTime = new Date(`2023-01-01T${departureTime}`);
                    depTime.setMinutes(depTime.getMinutes() + totalMinutes);
                    
                    const arrivalHours = depTime.getHours().toString().padStart(2, '0');
                    const arrivalMinutes = depTime.getMinutes().toString().padStart(2, '0');
                    
                    document.getElementById('arrival_time').value = `${arrivalHours}:${arrivalMinutes}`;
                    
                    // Show success message
                    const arrivalField = document.getElementById('arrival_time');
                    arrivalField.classList.add('is-valid');
                    setTimeout(() => {
                        arrivalField.classList.remove('is-valid');
                    }, 3000);
                    
                    return;
                }
            }
            
            // If no matching route found
            document.getElementById('arrival_time').value = '';
            alert('No matching route found. Please select a valid origin and destination.');
        }
        
        // Handle edit schedule modal
        const editScheduleModal = document.getElementById('editScheduleModal');
        editScheduleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const scheduleId = button.getAttribute('data-id');
            
            // Fetch schedule data using AJAX
            fetch(`../../backend/connections/get_schedule.php?id=${scheduleId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_schedule_id').value = data.id;
                    document.getElementById('edit_bus_id').value = data.bus_id;
                    document.getElementById('edit_origin').value = data.origin;
                    document.getElementById('edit_destination').value = data.destination;
                    document.getElementById('edit_departure_time').value = data.departure_time;
                    document.getElementById('edit_arrival_time').value = data.arrival_time;
                    document.getElementById('edit_fare_amount').value = data.fare_amount;
                    document.getElementById('edit_trip_number').value = data.trip_number || '';
                    document.getElementById('edit_status').value = data.status;
                    document.getElementById('edit_recurring').checked = data.recurring == 1;
                })
                .catch(error => {
                    console.error('Error fetching schedule data:', error);
                    alert('Failed to load schedule data. Please try again.');
                });
        });
        
        // Handle delete schedule modal
        const deleteScheduleModal = document.getElementById('deleteScheduleModal');
        deleteScheduleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const scheduleId = button.getAttribute('data-id');
            const scheduleName = button.getAttribute('data-name');
            
            document.getElementById('delete_schedule_id').value = scheduleId;
            document.getElementById('delete-schedule-name').textContent = scheduleName;
        });
        
        // Initialize popovers
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        });
        
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
    </script>
</body>
</html>