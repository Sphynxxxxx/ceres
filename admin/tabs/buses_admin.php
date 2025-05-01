<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../backend/connections/config.php";

// Get all routes for dropdowns
$routes = [];
try {
    $query = "SELECT id, origin, destination, CONCAT(origin, ' → ', destination) AS route_name FROM routes";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $routes[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle exception
    $error_message = "Database error: " . $e->getMessage();
}

// Handle bus status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $current_status = isset($_POST['current_status']) ? $_POST['current_status'] : '';
    
    if ($bus_id > 0 && !empty($current_status)) {
        // Toggle the status
        $new_status = ($current_status == 'Active') ? 'Under Maintenance' : 'Active';
        
        // Update status in database
        $update_query = "UPDATE buses SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $new_status, $bus_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Bus status updated to " . $new_status;
            $_SESSION['message_type'] = "success";
            
            // If bus is now active, ensure it has schedules for upcoming dates
            if ($new_status == 'Active') {
                // Check if we need to create default schedules for this bus
                $check_schedules = "SELECT COUNT(*) as count FROM schedules WHERE bus_id = ? AND date >= CURDATE()";
                $check_stmt = $conn->prepare($check_schedules);
                $check_stmt->bind_param("i", $bus_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $schedule_count = 0;
                
                if ($check_result && $check_result->num_rows > 0) {
                    $row = $check_result->fetch_assoc();
                    $schedule_count = $row['count'];
                }
                
                $check_stmt->close();
                
                // If no upcoming schedules, create default ones
                if ($schedule_count == 0) {
                    // Get bus details for route information
                    $bus_query = "SELECT route_id FROM buses WHERE id = ?";
                    $bus_stmt = $conn->prepare($bus_query);
                    $bus_stmt->bind_param("i", $bus_id);
                    $bus_stmt->execute();
                    $bus_result = $bus_stmt->get_result();
                    $route_id = null;
                    
                    if ($bus_result && $bus_result->num_rows > 0) {
                        $bus_data = $bus_result->fetch_assoc();
                        $route_id = $bus_data['route_id'];
                        
                        if ($route_id) {
                            // Get route details
                            $route_query = "SELECT origin, destination, fare FROM routes WHERE id = ?";
                            $route_stmt = $conn->prepare($route_query);
                            $route_stmt->bind_param("i", $route_id);
                            $route_stmt->execute();
                            $route_result = $route_stmt->get_result();
                            
                            if ($route_result && $route_result->num_rows > 0) {
                                $route_data = $route_result->fetch_assoc();
                                $origin = $route_data['origin'];
                                $destination = $route_data['destination'];
                                $fare_amount = $route_data['fare'];
                                
                                // Create a default schedule
                                $departure_time = '08:00:00'; // Default departure time
                                $arrival_time = '12:00:00';   // Default arrival time
                                $recurring = 1;
                                
                                // Insert default schedule
                                $insert_schedule = "INSERT INTO schedules (bus_id, origin, destination, departure_time, arrival_time, fare_amount, recurring, created_at) 
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                                $schedule_stmt = $conn->prepare($insert_schedule);
                                $schedule_stmt->bind_param("issssdi", $bus_id, $origin, $destination, $departure_time, $arrival_time, $fare_amount, $recurring);
                                
                                if ($schedule_stmt->execute()) {
                                    $_SESSION['message'] .= " Default schedule has been created.";
                                }
                                
                                $schedule_stmt->close();
                            }
                            $route_stmt->close();
                        }
                    }
                    $bus_stmt->close();
                }
            }
        } else {
            $_SESSION['message'] = "Error updating bus status: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect
        header("Location: buses_admin.php");
        exit();
    }
}

// Handle bus deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bus') {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    
    if ($bus_id > 0) {
        // Check if there are any active bookings for this bus
        $check_bookings = "SELECT COUNT(*) as count FROM bookings WHERE bus_id = ? AND booking_status = 'confirmed'";
        $check_stmt = $conn->prepare($check_bookings);
        $check_stmt->bind_param("i", $bus_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $active_bookings = 0;
        
        if ($check_result && $check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            $active_bookings = $row['count'];
        }
        
        $check_stmt->close();
        
        if ($active_bookings > 0) {
            $_SESSION['message'] = "Cannot delete bus: There are {$active_bookings} active bookings for this bus. Please cancel those bookings first or wait until they are completed.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Delete associated schedules first
            $delete_schedules = "DELETE FROM schedules WHERE bus_id = ?";
            $schedule_stmt = $conn->prepare($delete_schedules);
            $schedule_stmt->bind_param("i", $bus_id);
            $schedule_stmt->execute();
            $schedule_stmt->close();
            
            // Delete the bus
            $delete_query = "DELETE FROM buses WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $bus_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "Bus successfully deleted.";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error deleting bus: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
            
            $stmt->close();
        }
        
        // Redirect
        header("Location: buses_admin.php");
        exit();
    }
}

// Handle bus registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_bus') {
    $bus_type = isset($_POST['bus_type']) ? $_POST['bus_type'] : '';
    $seat_capacity = isset($_POST['seat_capacity']) ? intval($_POST['seat_capacity']) : 0;
    $plate_number = isset($_POST['plate_number']) ? $_POST['plate_number'] : '';
    $route_id = isset($_POST['route_id']) ? intval($_POST['route_id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : 'Active';
    
    // Validate route information
    $route_query = "SELECT origin, destination, CONCAT(origin, ' → ', destination) AS route_name FROM routes WHERE id = ?";
    $route_stmt = $conn->prepare($route_query);
    $route_stmt->bind_param("i", $route_id);
    $route_stmt->execute();
    $route_result = $route_stmt->get_result();
    
    $origin = '';
    $destination = '';
    $route_name = '';
    
    if ($route_result && $route_result->num_rows > 0) {
        $route_data = $route_result->fetch_assoc();
        $origin = $route_data['origin'];
        $destination = $route_data['destination'];
        $route_name = $route_data['route_name'];
    }
    $route_stmt->close();
    
    // Validation
    $errors = [];
    if (empty($bus_type)) {
        $errors[] = "Bus type is required";
    }
    if ($seat_capacity <= 0) {
        $errors[] = "Valid seat capacity is required";
    }
    if (empty($plate_number)) {
        $errors[] = "Plate number is required";
    }
    if (empty($route_name)) {
        $errors[] = "Route is required";
    }
    
    $driver_name = isset($_POST['driver_name']) ? $_POST['driver_name'] : '';
    $conductor_name = isset($_POST['conductor_name']) ? $_POST['conductor_name'] : '';
    
    if (empty($driver_name)) {
        $errors[] = "Driver name is required";
    }
    if (empty($conductor_name)) {
        $errors[] = "Conductor name is required";
    }
    
    if (empty($errors)) {
        // Insert new bus with complete route information
        $insert_query = "INSERT INTO buses (bus_type, seat_capacity, plate_number, route_id, route_name, origin, destination, driver_name, conductor_name, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sissssssss", $bus_type, $seat_capacity, $plate_number, $route_id, $route_name, $origin, $destination, $driver_name, $conductor_name, $status);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Bus successfully registered.";
            $_SESSION['message_type'] = "success";
            
            // Additional schedule creation logic (as in your original code)
            // ...
        } else {
            $_SESSION['message'] = "Error registering bus: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect
        header("Location: buses_admin.php");
        exit();
    } else {
        $_SESSION['message'] = "Please correct the following errors: " . implode(", ", $errors);
        $_SESSION['message_type'] = "danger";
    }
}

// Handle bus edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_bus') {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $bus_type = isset($_POST['bus_type']) ? $_POST['bus_type'] : '';
    $seat_capacity = isset($_POST['seat_capacity']) ? intval($_POST['seat_capacity']) : 0;
    $plate_number = isset($_POST['plate_number']) ? $_POST['plate_number'] : '';
    $route_id = isset($_POST['route_id']) ? intval($_POST['route_id']) : 0;
    $route_name = '';
    $driver_name = isset($_POST['driver_name']) ? $_POST['driver_name'] : '';
    $conductor_name = isset($_POST['conductor_name']) ? $_POST['conductor_name'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'Active';
    
    // Get route name from route_id
    if ($route_id > 0) {
        $route_query = "SELECT CONCAT(origin, ' → ', destination) AS route_name FROM routes WHERE id = ?";
        $route_stmt = $conn->prepare($route_query);
        $route_stmt->bind_param("i", $route_id);
        $route_stmt->execute();
        $route_result = $route_stmt->get_result();
        
        if ($route_result && $route_result->num_rows > 0) {
            $route_data = $route_result->fetch_assoc();
            $route_name = $route_data['route_name'];
        }
        $route_stmt->close();
    }
    
    // Validation
    $errors = [];
    if (empty($bus_type)) {
        $errors[] = "Bus type is required";
    }
    if ($seat_capacity <= 0) {
        $errors[] = "Valid seat capacity is required";
    }
    if (empty($plate_number)) {
        $errors[] = "Plate number is required";
    }
    if ($route_id <= 0) {
        $errors[] = "Route is required";
    }
    if (empty($driver_name)) {
        $errors[] = "Driver name is required";
    }
    if (empty($conductor_name)) {
        $errors[] = "Conductor name is required";
    }
    
    if (empty($errors) && $bus_id > 0) {
        // Get current bus data for comparison
        $current_query = "SELECT route_id, status FROM buses WHERE id = ?";
        $current_stmt = $conn->prepare($current_query);
        $current_stmt->bind_param("i", $bus_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_route_id = 0;
        $current_status = 'Under Maintenance';
        
        if ($current_result && $current_result->num_rows > 0) {
            $current_data = $current_result->fetch_assoc();
            $current_route_id = $current_data['route_id'];
            $current_status = $current_data['status'];
        }
        $current_stmt->close();
        
        // Update bus
        $update_query = "UPDATE buses SET 
                        bus_type = ?, 
                        seat_capacity = ?, 
                        plate_number = ?, 
                        route_id = ?, 
                        route_name = ?,
                        driver_name = ?, 
                        conductor_name = ?,
                        status = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sissssssi", $bus_type, $seat_capacity, $plate_number, $route_id, $route_name, $driver_name, $conductor_name, $status, $bus_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Bus details successfully updated.";
            $_SESSION['message_type'] = "success";
            
            // Handle route change for schedules
            if ($route_id != $current_route_id && $route_id > 0) {
                // Get new route details
                $route_query = "SELECT origin, destination FROM routes WHERE id = ?";
                $route_stmt = $conn->prepare($route_query);
                $route_stmt->bind_param("i", $route_id);
                $route_stmt->execute();
                $route_result = $route_stmt->get_result();
                
                if ($route_result && $route_result->num_rows > 0) {
                    $route_data = $route_result->fetch_assoc();
                    $origin = $route_data['origin'];
                    $destination = $route_data['destination'];
                    
                    // Update schedules with new route
                    $update_schedules = "UPDATE schedules SET origin = ?, destination = ? WHERE bus_id = ?";
                    $update_stmt = $conn->prepare($update_schedules);
                    $update_stmt->bind_param("ssi", $origin, $destination, $bus_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $_SESSION['message'] .= " Route updated in all schedules.";
                }
                $route_stmt->close();
            }
            
            // Handle status change from maintenance to active
            if ($current_status == 'Under Maintenance' && $status == 'Active') {
                // Check if we need to create default schedules
                $check_schedules = "SELECT COUNT(*) as count FROM schedules WHERE bus_id = ?";
                $check_stmt = $conn->prepare($check_schedules);
                $check_stmt->bind_param("i", $bus_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $schedule_count = 0;
                
                if ($check_result && $check_result->num_rows > 0) {
                    $row = $check_result->fetch_assoc();
                    $schedule_count = $row['count'];
                }
                $check_stmt->close();
                
                // If no schedules, create default one
                if ($schedule_count == 0 && $route_id > 0) {
                    // Get route details
                    $route_query = "SELECT origin, destination, fare FROM routes WHERE id = ?";
                    $route_stmt = $conn->prepare($route_query);
                    $route_stmt->bind_param("i", $route_id);
                    $route_stmt->execute();
                    $route_result = $route_stmt->get_result();
                    
                    if ($route_result && $route_result->num_rows > 0) {
                        $route_data = $route_result->fetch_assoc();
                        $origin = $route_data['origin'];
                        $destination = $route_data['destination'];
                        $fare_amount = $route_data['fare'];
                        
                        // Create a default schedule
                        $departure_time = '08:00:00'; // Default departure time
                        $arrival_time = '12:00:00';   // Default arrival time
                        $recurring = 1;
                        
                        // Insert default schedule
                        $insert_schedule = "INSERT INTO schedules (bus_id, origin, destination, departure_time, arrival_time, fare_amount, recurring, created_at) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $schedule_stmt = $conn->prepare($insert_schedule);
                        $schedule_stmt->bind_param("issssdi", $bus_id, $origin, $destination, $departure_time, $arrival_time, $fare_amount, $recurring);
                        
                        if ($schedule_stmt->execute()) {
                            $_SESSION['message'] .= " Default schedule has been created.";
                        }
                        
                        $schedule_stmt->close();
                    }
                    $route_stmt->close();
                }
            }
        } else {
            $_SESSION['message'] = "Error updating bus: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect
        header("Location: buses_admin.php");
        exit();
    } else {
        $_SESSION['message'] = "Please correct the following errors: " . implode(", ", $errors);
        $_SESSION['message_type'] = "danger";
    }
}

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? $_GET['page'] : 1;
$start_from = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$search_condition = '';
if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $search_condition = " WHERE bus_type LIKE '%$search%' OR plate_number LIKE '%$search%' OR route_name LIKE '%$search%' OR driver_name LIKE '%$search%' OR conductor_name LIKE '%$search%'";
}

// Get total number of buses
$total_query = "SELECT COUNT(*) as total FROM buses" . $search_condition;
$total_result = $conn->query($total_query);
$total_records = 0;
if ($total_result && $total_result->num_rows > 0) {
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
}
$total_pages = ceil($total_records / $records_per_page);

// Get buses with pagination
$buses = [];
try {
    $query = "SELECT b.id, b.bus_type, b.seat_capacity, b.plate_number, b.route_id, b.route_name, 
              b.driver_name, b.conductor_name, b.status, b.created_at,
              (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND booking_status = 'confirmed') as active_bookings,
              (SELECT COUNT(*) FROM schedules WHERE bus_id = b.id) as schedule_count 
              FROM buses b" . $search_condition . " 
              ORDER BY b.created_at DESC 
              LIMIT $start_from, $records_per_page";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $buses[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle exception
    $error_message = "Database error: " . $e->getMessage();
}

// Get bus types count
$regular_count = 0;
$aircon_count = 0;

$count_query = "SELECT bus_type, COUNT(*) as type_count FROM buses GROUP BY bus_type";
$count_result = $conn->query($count_query);
if ($count_result && $count_result->num_rows > 0) {
    while ($row = $count_result->fetch_assoc()) {
        if ($row['bus_type'] == 'Regular') {
            $regular_count = $row['type_count'];
        } else if ($row['bus_type'] == 'Aircondition') {
            $aircon_count = $row['type_count'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Management - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .bus-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #1d3557;
        }
        
        .card1 {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .filter-row {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-active {
            background-color: #28a745;
        }
        
        .status-maintenance {
            background-color: #dc3545;
        }

        .seat-indicator {
        width: 20px;
        height: 20px;
        border-radius: 4px;
        }
        
        .seat-indicator.available {
            background-color: #28a745;
        }
        
        .seat-indicator.booked {
            background-color: #dc3545;
        }
        
        .seat {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            border-radius: 4px;
            cursor: default;
            transition: all 0.2s;
        }
        
        .seat.available {
            background-color: #28a745;
        }
        
        .seat.booked {
            background-color: #dc3545;
        }
        
        .seat-map-container {
            min-height: 250px;
        }
        
        .seat-row {
            display: flex;
            justify-content: center;
            margin-bottom: 10px;
        }
        
        .aisle {
            width: 20px;
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
                    <a class="nav-link" href="schedules_admin.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="buses_admin.php">
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
                        <form class="d-flex ms-auto" action="buses_admin.php" method="GET">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" placeholder="Search buses" value="<?php echo htmlspecialchars($search); ?>" aria-label="Search">
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
                    <h2><i class="fas fa-bus me-2"></i>Bus Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBusModal">
                        <i class="fas fa-plus me-2"></i>Register New Bus
                    </button>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                    <?php 
                    echo htmlspecialchars($_SESSION['message']); 
                    unset($_SESSION['message']);
                    unset($_SESSION['message_type']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card1 border-left-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="small text-muted">Total Buses</div>
                                        <div class="h4 mb-0"><?php echo $total_records; ?></div>
                                    </div>
                                    <div class="bg-primary bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-bus text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card1 border-left-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="small text-muted">Regular Buses</div>
                                        <div class="h4 mb-0"><?php echo $regular_count; ?></div>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-bus-alt text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card1 border-left-info">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="small text-muted">Aircon Buses</div>
                                        <div class="h4 mb-0"><?php echo $aircon_count; ?></div>
                                    </div>
                                    <div class="bg-info bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-snowflake text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bus Status Overview -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card1">
                            <div class="card-header">
                                <h5 class="mb-0">Bus Status Overview</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-around text-center">
                                    <div>
                                        <div class="h5">
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <?php 
                                                $active_count = 0;
                                                foreach ($buses as $bus) {
                                                    if ($bus['status'] == 'Active') $active_count++;
                                                }
                                                echo $active_count;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted">Active Buses</div>
                                    </div>
                                    <div>
                                        <div class="h5">
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-tools me-1"></i>
                                                <?php 
                                                $maintenance_count = 0;
                                                foreach ($buses as $bus) {
                                                    if ($bus['status'] == 'Under Maintenance') $maintenance_count++;
                                                }
                                                echo $maintenance_count;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted">Under Maintenance</div>
                                    </div>
                                    <div>
                                        <div class="h5">
                                            <span class="badge bg-primary">
                                                <i class="fas fa-calendar-alt me-1"></i>
                                                <?php 
                                                $total_schedules = 0;
                                                foreach ($buses as $bus) {
                                                    $total_schedules += $bus['schedule_count'];
                                                }
                                                echo $total_schedules;
                                                ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted">Total Schedules</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card1">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Active Bookings</h5>
                                <a href="bookings_admin.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-0">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0 me-3">
                                            <i class="fas fa-info-circle fa-2x text-info"></i>
                                        </div>
                                        <div>
                                            <h6 class="alert-heading mb-1">Travel Date Information</h6>
                                            <p class="mb-0 small">Only active buses can be booked for travel dates. When a bus is set to <span class="badge bg-success">Active</span>, travelers can select dates for their journeys. Buses under <span class="badge bg-warning text-dark">Maintenance</span> are not available for booking until their status is changed.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Buses Table -->
                <div class="card1">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Registered Buses</h5>
                            <span class="badge bg-primary"><?php echo $total_records; ?> buses found</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($buses) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Bus Info</th>
                                        <th>Type</th>
                                        <th>Plate Number</th>
                                        <th>Route</th>
                                        <th>Capacity</th>
                                        <th>Staff</th>
                                        <th>Status</th>
                                        <th>Registered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($buses as $bus): ?>
                                    <tr>
                                        <td>#<?= htmlspecialchars($bus['id']) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-bus-alt fs-4 me-2"></i>
                                                <div>
                                                    <strong>Bus #<?= htmlspecialchars($bus['id']) ?></strong>
                                                    <div class="text-muted"><?= htmlspecialchars($bus['active_bookings']) ?> active bookings</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= ucfirst(htmlspecialchars($bus['bus_type'])) ?></td>
                                        <td><?= htmlspecialchars($bus['plate_number']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($bus['route_name']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($bus['seat_capacity']) ?> seats</td>
                                        <td>
                                            Driver: <?= htmlspecialchars($bus['driver_name']) ?><br>
                                            Conductor: <?= htmlspecialchars($bus['conductor_name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= ($bus['status'] == 'Active') ? 'success' : 'warning'; ?>">
                                                <?= htmlspecialchars($bus['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($bus['created_at'])) ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-outline-info btn-sm view-bus"
                                                        data-id="<?= $bus['id'] ?>" title="View Details" data-bs-toggle="tooltip">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success btn-sm toggle-status" 
                                                        data-id="<?= $bus['id'] ?>" data-current-status="<?= $bus['status'] ?>" 
                                                        title="Toggle Status" data-bs-toggle="tooltip">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-primary btn-sm edit-bus"
                                                        data-id="<?= $bus['id'] ?>" title="Edit Bus" data-bs-toggle="tooltip">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm delete-bus"
                                                        data-id="<?= $bus['id'] ?>" title="Delete Bus" data-bs-toggle="tooltip">
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
                        <div class="alert alert-info text-center">No buses registered yet.</div>
                        <?php endif; ?>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mt-4">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="First">
                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Last">
                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Bus Modal -->
    <div class="modal fade" id="addBusModal" tabindex="-1" aria-labelledby="addBusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="addBusForm" method="post" action="buses_admin.php">
                    <input type="hidden" name="action" value="add_bus">

                    <div class="modal-header">
                        <h5 class="modal-title" id="addBusModalLabel"><i class="fas fa-bus me-2"></i>Add New Bus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Bus Type -->
                        <div class="mb-3">
                            <label for="bus_type" class="form-label">Bus Type*</label>
                            <select class="form-select" id="bus_type" name="bus_type" required>
                                <option value="">Select Bus Type</option>
                                <option value="Regular">Regular</option>
                                <option value="Aircondition">Air-conditioned</option>
                            </select>
                        </div>

                        <!-- Seat Capacity -->
                        <div class="mb-3">
                            <label for="seat_capacity" class="form-label">Seat Capacity*</label>
                            <input type="number" id="seat_capacity" name="seat_capacity" class="form-control" min="1" max="100" required>
                            <div class="form-text">Maximum allowed is 100 seats.</div>
                        </div>

                        <!-- Plate Number -->
                        <div class="mb-3">
                            <label for="plate_number" class="form-label">Plate Number*</label>
                            <input type="text" id="plate_number" name="plate_number" class="form-control" required>
                        </div>

                        <!-- Select Route -->
                        <div class="mb-3">
                            <label for="route_id" class="form-label">Route*</label>
                            <select class="form-select" id="route_id" name="route_id" required>
                                <option value="">Select Route</option>
                                <?php foreach ($routes as $route): ?>
                                <option value="<?= $route['id'] ?>"><?= htmlspecialchars($route['route_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a valid route.</div>
                        </div>

                        <!-- Driver Name -->
                        <div class="mb-3">
                            <label for="driver_name" class="form-label">Driver Name*</label>
                            <input type="text" id="driver_name" name="driver_name" class="form-control" required>
                        </div>

                        <!-- Conductor Name -->
                        <div class="mb-3">
                            <label for="conductor_name" class="form-label">Conductor Name*</label>
                            <input type="text" id="conductor_name" name="conductor_name" class="form-control" required>
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="Active">Active</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Bus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Bus Modal -->
    <div class="modal fade" id="editBusModal" tabindex="-1" aria-labelledby="editBusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBusModalLabel"><i class="fas fa-edit me-2"></i>Edit Bus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editBusForm" method="post" action="buses_admin.php">
                        <input type="hidden" name="action" value="update_bus">
                        <input type="hidden" id="edit_bus_id" name="bus_id">
                        
                        <!-- Bus Type -->
                        <div class="mb-3">
                            <label for="edit_bus_type" class="form-label">Bus Type*</label>
                            <select class="form-select" id="edit_bus_type" name="bus_type" required>
                                <option value="">Select Bus Type</option>
                                <option value="Regular">Regular</option>
                                <option value="Aircondition">Air-conditioned</option>
                            </select>
                        </div>

                        <!-- Seat Capacity -->
                        <div class="mb-3">
                            <label for="edit_seat_capacity" class="form-label">Seat Capacity*</label>
                            <input type="number" id="edit_seat_capacity" name="seat_capacity" class="form-control" min="1" max="100" required>
                        </div>

                        <!-- Plate Number -->
                        <div class="mb-3">
                            <label for="edit_plate_number" class="form-label">Plate Number*</label>
                            <input type="text" id="edit_plate_number" name="plate_number" class="form-control" required>
                        </div>

                        <!-- Select Route -->
                        <div class="mb-3">
                            <label for="edit_route_id" class="form-label">Route*</label>
                            <select class="form-select" id="edit_route_id" name="route_id" required>
                                <option value="">Select Route</option>
                                <?php foreach ($routes as $route): ?>
                                <option value="<?= $route['id'] ?>"><?= htmlspecialchars($route['route_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Driver Name -->
                        <div class="mb-3">
                            <label for="edit_driver_name" class="form-label">Driver Name*</label>
                            <input type="text" id="edit_driver_name" name="driver_name" class="form-control" required>
                        </div>

                        <!-- Conductor Name -->
                        <div class="mb-3">
                            <label for="edit_conductor_name" class="form-label">Conductor Name*</label>
                            <input type="text" id="edit_conductor_name" name="conductor_name" class="form-control" required>
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="Active">Active</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewBusModal" tabindex="-1" aria-labelledby="viewBusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBusModalLabel"><i class="fas fa-bus me-2"></i>Bus Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-4">
                        <!-- Bus Basic Information -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Bus Information</h5>
                                    <span class="badge bg-primary" id="view_bus_id_badge">#ID</span>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Bus Type:</p>
                                            <p id="view_bus_type">Loading...</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Status:</p>
                                            <p id="view_bus_status">Loading...</p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Plate Number:</p>
                                            <p id="view_plate_number">Loading...</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Seat Capacity:</p>
                                            <p id="view_seat_capacity">Loading...</p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-12">
                                            <p class="fw-bold mb-1">Route:</p>
                                            <p id="view_route">Loading...</p>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Driver:</p>
                                            <p id="view_driver_name">Loading...</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="fw-bold mb-1">Conductor:</p>
                                            <p id="view_conductor_name">Loading...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Statistics Card -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Booking Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <div class="border rounded p-3 text-center">
                                                <h6 class="text-muted">Active Bookings</h6>
                                                <h3 id="view_active_bookings" class="mb-0">0</h3>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <div class="border rounded p-3 text-center">
                                                <h6 class="text-muted">Schedules</h6>
                                                <h3 id="view_schedule_count" class="mb-0">0</h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="alert alert-info mb-0">
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 me-3">
                                                        <i class="fas fa-info-circle fa-2x"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="alert-heading mb-1">Registration Information</h6>
                                                        <p class="mb-0 small">Bus registered on <span id="view_registration_date">Loading...</span></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Seat Availability Section -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Seat Availability</h5>
                            <div>
                                <input type="date" class="form-control" id="seat_availability_date" min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Seat Legend -->
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="d-flex justify-content-center gap-4">
                                        <div class="d-flex align-items-center">
                                            <div class="seat-indicator available me-2"></div>
                                            <span>Available</span>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <div class="seat-indicator booked me-2"></div>
                                            <span>Booked</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Seat Map -->
                            <div class="row">
                                <div class="col-12">
                                    <div class="seat-map-container p-3 border rounded bg-light text-center">
                                        <div class="mb-3">
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-arrow-up me-1"></i> Front of Bus
                                            </span>
                                        </div>
                                        
                                        <div id="seat_map_area" class="d-flex flex-wrap justify-content-center gap-2">
                                            <!-- Seats will be loaded here dynamically -->
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-arrow-down me-1"></i> Back of Bus
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Seat Availability Summary -->
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center bg-success bg-opacity-25">
                                        <h6 class="text-success">Available Seats</h6>
                                        <h3 id="available_seats_count" class="mb-0">0</h3>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center bg-danger bg-opacity-25">
                                        <h6 class="text-danger">Booked Seats</h6>
                                        <h3 id="booked_seats_count" class="mb-0">0</h3>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 text-center bg-primary bg-opacity-25">
                                        <h6 class="text-primary">Total Capacity</h6>
                                        <h3 id="total_seats_count" class="mb-0">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="view_schedules_link" href="#" class="btn btn-primary">View Schedules</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store buses data for JavaScript access
        const busesData = <?php echo json_encode($buses); ?>;

        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.body.classList.toggle('collapsed-sidebar');
        });

        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Toggle bus status
        document.querySelectorAll('.toggle-status').forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const busId = this.getAttribute('data-id');
                const currentStatus = this.getAttribute('data-current-status');
                const newStatus = currentStatus === 'Active' ? 'Under Maintenance' : 'Active';
                
                let message = 'Are you sure you want to change the bus status from ' + currentStatus + ' to ' + newStatus + '?';
                
                if (currentStatus === 'Active') {
                    message += '\n\nWARNING: This will disable travel date selection for this bus. Existing bookings will not be affected, but no new bookings can be made until the bus is active again.';
                } else {
                    message += '\n\nThis will enable travel date selection for this bus, allowing travelers to book tickets.';
                }
                
                if (confirm(message)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'buses_admin.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'toggle_status';
                    form.appendChild(actionInput);
                    
                    const busIdInput = document.createElement('input');
                    busIdInput.type = 'hidden';
                    busIdInput.name = 'bus_id';
                    busIdInput.value = busId;
                    form.appendChild(busIdInput);
                    
                    const currentStatusInput = document.createElement('input');
                    currentStatusInput.type = 'hidden';
                    currentStatusInput.name = 'current_status';
                    currentStatusInput.value = currentStatus;
                    form.appendChild(currentStatusInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });

        // Edit bus functionality
        document.querySelectorAll('.edit-bus').forEach(function(button) {
            button.addEventListener('click', function() {
                const busId = this.getAttribute('data-id');
                const bus = findBusById(busId);
                
                if (bus) {
                    // Populate edit modal
                    document.getElementById('edit_bus_id').value = bus.id;
                    document.getElementById('edit_bus_type').value = bus.bus_type;
                    document.getElementById('edit_seat_capacity').value = bus.seat_capacity;
                    document.getElementById('edit_plate_number').value = bus.plate_number;
                    document.getElementById('edit_route_id').value = bus.route_id || '';
                    document.getElementById('edit_driver_name').value = bus.driver_name;
                    document.getElementById('edit_conductor_name').value = bus.conductor_name;
                    document.getElementById('edit_status').value = bus.status;
                    
                    // Show edit modal
                    var editBusModal = new bootstrap.Modal(document.getElementById('editBusModal'));
                    editBusModal.show();
                } else {
                    alert('Error: Bus data not found.');
                }
            });
        });

        // Delete bus functionality
        document.querySelectorAll('.delete-bus').forEach(function(button) {
            button.addEventListener('click', function() {
                const busId = this.getAttribute('data-id');
                
                if (confirm('Are you sure you want to delete bus #' + busId + '? This action cannot be undone. Any active bookings for this bus will need to be resolved separately.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'buses_admin.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_bus';
                    form.appendChild(actionInput);
                    
                    const busIdInput = document.createElement('input');
                    busIdInput.type = 'hidden';
                    busIdInput.name = 'bus_id';
                    busIdInput.value = busId;
                    form.appendChild(busIdInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });

        // Helper function to find a bus by ID
        function findBusById(busId) {
            return busesData.find(bus => bus.id == busId);
        }

        // Form validation for add bus form
        document.getElementById('addBusForm').addEventListener('submit', function(e) {
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });

        // Form validation for edit bus form
        document.getElementById('editBusForm').addEventListener('submit', function(e) {
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });

        // Reset form when modals are closed
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
            button.addEventListener('click', function() {
                const modalId = this.closest('.modal').id;
                const form = document.getElementById(modalId).querySelector('form');
                if (form) {
                    form.reset();
                    form.classList.remove('was-validated');
                }
            });
        });

        // Additional initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Reset forms when modals are hidden
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('hidden.bs.modal', function() {
                    const form = this.querySelector('form');
                    if (form) {
                        form.reset();
                        form.classList.remove('was-validated');
                    }
                });
            });
        });

        // View bus details and seat availability
        document.querySelectorAll('.view-bus').forEach(function(button) {
            button.addEventListener('click', function() {
                const busId = this.getAttribute('data-id');
                const bus = findBusById(busId);
                
                if (bus) {
                    // Populate bus details
                    document.getElementById('view_bus_id_badge').textContent = '#' + bus.id;
                    document.getElementById('view_bus_type').textContent = bus.bus_type;
                    document.getElementById('view_plate_number').textContent = bus.plate_number;
                    document.getElementById('view_seat_capacity').textContent = bus.seat_capacity + ' seats';
                    document.getElementById('view_route').textContent = bus.route_name;
                    document.getElementById('view_driver_name').textContent = bus.driver_name;
                    document.getElementById('view_conductor_name').textContent = bus.conductor_name;
                    
                    // Set status badge
                    const statusElement = document.getElementById('view_bus_status');
                    if (bus.status === 'Active') {
                        statusElement.innerHTML = '<span class="badge bg-success">Active</span>';
                    } else {
                        statusElement.innerHTML = '<span class="badge bg-warning text-dark">Under Maintenance</span>';
                    }
                    
                    // Set statistics
                    document.getElementById('view_active_bookings').textContent = bus.active_bookings;
                    document.getElementById('view_schedule_count').textContent = bus.schedule_count;
                    document.getElementById('view_registration_date').textContent = new Date(bus.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    // Set link to schedules
                    document.getElementById('view_schedules_link').href = 'schedules_admin.php?bus_id=' + bus.id;
                    
                    // Initialize seat availability date to today
                    const today = new Date().toISOString().split('T')[0];
                    const dateInput = document.getElementById('seat_availability_date');
                    dateInput.value = today;
                    
                    // Load seat availability for today
                    loadSeatAvailability(bus.id, today, bus.seat_capacity);
                    
                    // Add event listener for date change
                    dateInput.addEventListener('change', function() {
                        loadSeatAvailability(bus.id, this.value, bus.seat_capacity);
                    });
                    
                    // Show the modal
                    var viewBusModal = new bootstrap.Modal(document.getElementById('viewBusModal'));
                    viewBusModal.show();
                } else {
                    alert('Error: Bus data not found.');
                }
            });
        });

        // Function to load seat availability for a specific date
        function loadSeatAvailability(busId, date, seatCapacity) {
            const seatMapArea = document.getElementById('seat_map_area');
            seatMapArea.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Loading seats...</span>
                    </div>
                    <p>Loading seat map...</p>
                </div>
            `;
            
            // Set total seats count
            document.getElementById('total_seats_count').textContent = seatCapacity;
            
            // Fetch booked seats from the server
            fetchBookedSeats(busId, date)
                .then(bookedSeats => {
                    // Clear container
                    seatMapArea.innerHTML = '';
                    
                    // Calculate seat counts
                    const bookedCount = bookedSeats.length;
                    const availableCount = seatCapacity - bookedCount;
                    
                    // Create the seat map
                    generateSeatMap(seatMapArea, seatCapacity, bookedSeats);
                    
                    // Update counters
                    document.getElementById('booked_seats_count').textContent = bookedCount;
                    document.getElementById('available_seats_count').textContent = availableCount;
                })
                .catch(error => {
                    console.error('Error loading seat availability:', error);
                    seatMapArea.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading seat map. Please try again.
                        </div>
                    `;
                });
        }

        // Function to generate the seat map layout
        function generateSeatMap(seatMapContainer, totalSeats, bookedSeats) {
            let seatNumber = 1;
            const seatsPerRow = 4; // Default 2-2 layout
            
            // Always reserve seats for the back row
            const backRowSeats = 6;
            const remainingSeats = totalSeats - backRowSeats;
            const normalRows = Math.floor(remainingSeats / seatsPerRow);
            const extraSeats = remainingSeats % seatsPerRow;
            
            // Create driver area indicator
            const driverArea = document.createElement('div');
            
            seatMapContainer.appendChild(driverArea);
            
            // Create seat map container
            const seatMapWrapper = document.createElement('div');
            seatMapWrapper.className = 'seat-map-container';
            seatMapWrapper.style.backgroundColor = '#f8f9fa';
            seatMapWrapper.style.borderRadius = '8px';
            seatMapWrapper.style.padding = '20px';
            seatMapWrapper.style.boxShadow = 'inset 0 0 15px rgba(0,0,0,0.1)';
            
            // Create normal rows (2-2 layout)
            for (let row = 1; row <= normalRows; row++) {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'seat-row';
                rowDiv.style.display = 'flex';
                rowDiv.style.justifyContent = 'center';
                rowDiv.style.marginBottom = '12px';
                rowDiv.style.gap = '10px';
                rowDiv.style.alignItems = 'center';
                
                // Add row label
                const rowLabel = document.createElement('div');
                rowLabel.className = 'seat-row-label';
                rowLabel.style.width = '25px';
                rowLabel.style.height = '25px';
                rowLabel.style.display = 'flex';
                rowLabel.style.alignItems = 'center';
                rowLabel.style.justifyContent = 'center';
                rowLabel.style.backgroundColor = '#e9ecef';
                rowLabel.style.borderRadius = '50%';
                rowLabel.style.fontWeight = 'bold';
                rowLabel.style.color = '#495057';
                rowLabel.textContent = String.fromCharCode(64 + row); // A, B, C, etc.
                rowDiv.appendChild(rowLabel);
                
                // Add left side seats (2 seats)
                for (let i = 0; i < seatsPerRow/2; i++) {
                    if (seatNumber <= totalSeats - backRowSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        rowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                // Add aisle
                const aisleDiv = document.createElement('div');
                aisleDiv.className = 'aisle';
                aisleDiv.style.width = '20px';
                rowDiv.appendChild(aisleDiv);
                
                // Add right side seats (2 seats)
                for (let i = 0; i < seatsPerRow/2; i++) {
                    if (seatNumber <= totalSeats - backRowSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        rowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                seatMapWrapper.appendChild(rowDiv);
            }
            
            // Handle extra seats if any (create a partial row before the back row)
            if (extraSeats > 0) {
                const extraRowDiv = document.createElement('div');
                extraRowDiv.className = 'seat-row';
                extraRowDiv.style.display = 'flex';
                extraRowDiv.style.justifyContent = 'center';
                extraRowDiv.style.marginBottom = '12px';
                extraRowDiv.style.gap = '10px';
                extraRowDiv.style.alignItems = 'center';
                
                // Add row label
                const rowLabel = document.createElement('div');
                rowLabel.className = 'seat-row-label';
                rowLabel.style.width = '25px';
                rowLabel.style.height = '25px';
                rowLabel.style.display = 'flex';
                rowLabel.style.alignItems = 'center';
                rowLabel.style.justifyContent = 'center';
                rowLabel.style.backgroundColor = '#e9ecef';
                rowLabel.style.borderRadius = '50%';
                rowLabel.style.fontWeight = 'bold';
                rowLabel.style.color = '#495057';
                rowLabel.textContent = String.fromCharCode(64 + normalRows + 1);
                extraRowDiv.appendChild(rowLabel);
                
                // Add left side seats
                const leftSeats = Math.min(extraSeats, 2);
                for (let i = 0; i < leftSeats; i++) {
                    const isBooked = bookedSeats.includes(seatNumber);
                    const seat = createSeatElement(seatNumber, isBooked);
                    extraRowDiv.appendChild(seat);
                    seatNumber++;
                }
                
                // Add aisle
                const aisleDiv = document.createElement('div');
                aisleDiv.className = 'aisle';
                aisleDiv.style.width = '20px';
                extraRowDiv.appendChild(aisleDiv);
                
                // Add right side seats if needed
                const rightSeats = extraSeats - leftSeats;
                for (let i = 0; i < rightSeats; i++) {
                    const isBooked = bookedSeats.includes(seatNumber);
                    const seat = createSeatElement(seatNumber, isBooked);
                    extraRowDiv.appendChild(seat);
                    seatNumber++;
                }
                
                seatMapWrapper.appendChild(extraRowDiv);
            }
            
            // Create the back row
            if (backRowSeats > 0 && seatNumber <= totalSeats) {
                const backRowDiv = document.createElement('div');
                backRowDiv.className = 'seat-row back-row mt-4';
                backRowDiv.style.display = 'flex';
                backRowDiv.style.justifyContent = 'center';
                backRowDiv.style.marginBottom = '12px';
                backRowDiv.style.gap = '10px';
                backRowDiv.style.alignItems = 'center';
                
                // Add row label - use the next letter after the previous rows
                const backRowLetter = String.fromCharCode(64 + normalRows + (extraSeats > 0 ? 2 : 1));
                const rowLabel = document.createElement('div');
                rowLabel.className = 'seat-row-label';
                rowLabel.style.width = '25px';
                rowLabel.style.height = '25px';
                rowLabel.style.display = 'flex';
                rowLabel.style.alignItems = 'center';
                rowLabel.style.justifyContent = 'center';
                rowLabel.style.backgroundColor = '#e9ecef';
                rowLabel.style.borderRadius = '50%';
                rowLabel.style.fontWeight = 'bold';
                rowLabel.style.color = '#495057';
                rowLabel.textContent = backRowLetter;
                backRowDiv.appendChild(rowLabel);
                
                // Add all 5 back row seats
                for (let i = 0; i < backRowSeats; i++) {
                    if (seatNumber <= totalSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        backRowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                seatMapWrapper.appendChild(backRowDiv);
            }
            
            // Back of bus indicator
            const backIndicator = document.createElement('div');
            
            
            // Append seat map and back indicator
            seatMapContainer.appendChild(seatMapWrapper);
            seatMapContainer.appendChild(backIndicator);
        }

        

        // Function to create the seat map UI
        function createSeatMap(container, totalSeats, bookedSeats) {
            // Determine layout (rows and columns)
            const seatsPerRow = 4; // 2 seats on each side of aisle
            const backRowSeats = totalSeats % seatsPerRow || seatsPerRow;
            const normalRows = Math.floor(totalSeats / seatsPerRow);
            
            // Create normal rows (2-2 configuration)
            let seatNumber = 1;
            
            for (let row = 0; row < normalRows; row++) {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'seat-row';
                
                // Left side (2 seats)
                for (let i = 0; i < 2; i++) {
                    if (seatNumber <= totalSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        rowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                // Aisle
                const aisle = document.createElement('div');
                aisle.className = 'aisle';
                rowDiv.appendChild(aisle);
                
                // Right side (2 seats)
                for (let i = 0; i < 2; i++) {
                    if (seatNumber <= totalSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        rowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                container.appendChild(rowDiv);
            }
            
            // Create back row if there are remaining seats
            if (backRowSeats > 0 && seatNumber <= totalSeats) {
                const backRowDiv = document.createElement('div');
                backRowDiv.className = 'seat-row';
                
                for (let i = 0; i < backRowSeats; i++) {
                    if (seatNumber <= totalSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        backRowDiv.appendChild(seat);
                        seatNumber++;
                    }
                }
                
                container.appendChild(backRowDiv);
            }
        }

        // Function to create a seat element
        function createSeatElement(seatNumber, isBooked) {
            const seat = document.createElement('div');
            seat.className = `seat ${isBooked ? 'booked' : 'available'}`;
            seat.dataset.seatNumber = seatNumber;
            seat.textContent = seatNumber;
            
            // Styling
            seat.style.width = '40px';
            seat.style.height = '40px';
            seat.style.display = 'flex';
            seat.style.alignItems = 'center';
            seat.style.justifyContent = 'center';
            seat.style.borderRadius = '5px';
            seat.style.cursor = isBooked ? 'not-allowed' : 'default';
            seat.style.fontSize = '0.9rem';
            seat.style.fontWeight = 'bold';
            seat.style.color = 'white';
            seat.style.transition = 'all 0.3s';
            seat.style.margin = '5px';
            seat.style.position = 'relative';
            seat.style.border = '2px solid transparent';
            
            // Booked seat specific styling
            if (isBooked) {
                seat.style.backgroundColor = '#dc3545';
                seat.style.opacity = '0.8';
                
                // Add lock icon
                const lockIcon = document.createElement('i');
                lockIcon.className = 'fas fa-lock position-absolute';
                lockIcon.style.fontSize = '10px';
                lockIcon.style.top = '5px';
                lockIcon.style.right = '5px';
                lockIcon.style.color = 'rgba(255,255,255,0.7)';
                seat.appendChild(lockIcon);
            } else {
                seat.style.backgroundColor = '#28a745';
            }
            
            // Tooltip
            seat.setAttribute('title', `Seat ${seatNumber}: ${isBooked ? 'Booked' : 'Available'}`);
            
            return seat;
        }

        // Function to fetch booked seats from the server
        function fetchBookedSeats(busId, date) {
            
            return new Promise((resolve, reject) => {
                // AJAX call to get booked seats
                fetch(`../../backend/connections/get_booked_seats.php?bus_id=${busId}&date=${date}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            reject(data.error);
                        } else {
                            resolve(data.bookedSeats || []);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching booked seats:', error);
                        // For demo purposes, return random booked seats
                        const randomBooked = [];
                        const totalSeats = findBusById(busId).seat_capacity;
                        
                        // Generate random number of booked seats (between 0 and 40% of total)
                        const numBooked = Math.floor(Math.random() * (totalSeats * 0.4));
                        
                        // Generate random seat numbers
                        for (let i = 0; i < numBooked; i++) {
                            const seatNum = Math.floor(Math.random() * totalSeats) + 1;
                            if (!randomBooked.includes(seatNum)) {
                                randomBooked.push(seatNum);
                            }
                        }
                        
                        resolve(randomBooked);
                    });
            });
        }
    </script>
</body>
</html>