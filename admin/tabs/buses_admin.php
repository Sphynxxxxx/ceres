<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../backend/connections/config.php";

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
                
                // If no upcoming schedules, create default ones if needed
                if ($schedule_count == 0) {
                    // Get bus details to set appropriate schedules
                    $bus_query = "SELECT origin, destination FROM buses WHERE id = ?";
                    $bus_stmt = $conn->prepare($bus_query);
                    $bus_stmt->bind_param("i", $bus_id);
                    $bus_stmt->execute();
                    $bus_result = $bus_stmt->get_result();
                    
                    if ($bus_result && $bus_result->num_rows > 0) {
                        $bus_data = $bus_result->fetch_assoc();
                        $origin = $bus_data['origin'];
                        $destination = $bus_data['destination'];
                        
                        // Create a default schedule for the next 30 days
                        $departure_time = '08:00:00'; // Default departure time
                        $arrival_time = '12:00:00';   // Default arrival time
                        $fare_amount = 150.00;        // Default fare
                        
                        // Set recurring flag
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
                    
                    $bus_stmt->close();
                }
            }
        } else {
            $_SESSION['message'] = "Error updating bus status: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'new_status' => $new_status]);
            exit;
        }
        
        // Redirect for non-AJAX requests
        header("Location: buses_admin.php");
        exit();
    }
}

// Handle bus deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bus') {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    
    if ($bus_id > 0) {
        // Check if there are any active bookings for this bus
        $check_bookings = "SELECT COUNT(*) as count FROM bookings WHERE bus_id = ? AND booking_date >= CURDATE() AND booking_status = 'confirmed'";
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
            
            // Prepare and execute delete query for the bus
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
        
        // Redirect to prevent form resubmission
        header("Location: buses_admin.php");
        exit();
    }
}

// Handle bus edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_bus') {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $bus_type = isset($_POST['bus_type']) ? $_POST['bus_type'] : '';
    $seat_capacity = isset($_POST['seat_capacity']) ? intval($_POST['seat_capacity']) : 0;
    $plate_number = isset($_POST['plate_number']) ? $_POST['plate_number'] : '';
    $origin = isset($_POST['origin']) ? $_POST['origin'] : '';
    $destination = isset($_POST['destination']) ? $_POST['destination'] : '';
    $driver_name = isset($_POST['driver_name']) ? $_POST['driver_name'] : '';
    $conductor_name = isset($_POST['conductor_name']) ? $_POST['conductor_name'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'Active';
    
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
    if (empty($origin)) {
        $errors[] = "Origin is required";
    }
    if (empty($destination)) {
        $errors[] = "Destination is required";
    }
    if (empty($driver_name)) {
        $errors[] = "Driver name is required";
    }
    if (empty($conductor_name)) {
        $errors[] = "Conductor name is required";
    }
    if ($status != 'Active' && $status != 'Under Maintenance') {
        $status = 'Active'; // Default to Active if invalid
    }
    if ($origin === $destination) {
        $errors[] = "Origin and destination cannot be the same";
    }
    
    if (empty($errors) && $bus_id > 0) {
        // First verify the origin/destination combination is valid
        $check_route = "SELECT id FROM buses WHERE id = ? AND origin = ? AND destination = ?";
        $check_stmt = $conn->prepare($check_route);
        $check_stmt->bind_param("iss", $bus_id, $origin, $destination);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            // Route has changed, need to update schedules
            $update_schedules = true;
        }
        
        
        // Update bus in database
        $update_query = "UPDATE buses SET 
                        bus_type = ?, 
                        seat_capacity = ?, 
                        plate_number = ?, 
                        origin = ?, 
                        destination = ?, 
                        driver_name = ?, 
                        conductor_name = ?,
                        status = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sississsi", $bus_type, $seat_capacity, $plate_number, $origin, $destination, $driver_name, $conductor_name, $status, $bus_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Bus details successfully updated.";
            $_SESSION['message_type'] = "success";
            
            // Check if status changed from Under Maintenance to Active
            if ($current_status == 'Under Maintenance' && $status == 'Active') {
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
                    // Create a default schedule
                    $departure_time = '08:00:00'; // Default departure time
                    $arrival_time = '12:00:00';   // Default arrival time
                    $fare_amount = 150.00;        // Default fare
                    $recurring = 1;               // Make it recurring
                    
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
            }
            
            // If origin or destination changed, update schedules
            if ($current_status != 'Under Maintenance' && $status == 'Active') {
                $update_schedules = "UPDATE schedules SET origin = ?, destination = ? WHERE bus_id = ?";
                $update_stmt = $conn->prepare($update_schedules);
                $update_stmt->bind_param("ssi", $origin, $destination, $bus_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            $_SESSION['message'] = "Error updating bus: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect to prevent form resubmission
        header("Location: buses_admin.php");
        exit();
    } elseif (!empty($errors)) {
        $_SESSION['message'] = "Please correct the following errors: " . implode(", ", $errors);
        $_SESSION['message_type'] = "danger";
    }
}

// Handle bus registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_bus') {
    $bus_type = isset($_POST['bus_type']) ? $_POST['bus_type'] : '';
    $seat_capacity = isset($_POST['seat_capacity']) ? intval($_POST['seat_capacity']) : 0;
    $plate_number = isset($_POST['plate_number']) ? $_POST['plate_number'] : '';
    $origin = isset($_POST['origin']) ? $_POST['origin'] : '';
    $destination = isset($_POST['destination']) ? $_POST['destination'] : '';
    $driver_name = isset($_POST['driver_name']) ? $_POST['driver_name'] : '';
    $conductor_name = isset($_POST['conductor_name']) ? $_POST['conductor_name'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'Active';
    
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
    if (empty($origin)) {
        $errors[] = "Origin is required";
    }
    if (empty($destination)) {
        $errors[] = "Destination is required";
    }
    if (empty($driver_name)) {
        $errors[] = "Driver name is required";
    }
    if (empty($conductor_name)) {
        $errors[] = "Conductor name is required";
    }
    if ($status != 'Active' && $status != 'Under Maintenance') {
        $status = 'Active'; // Default to Active if invalid
    }
    
    if (empty($errors)) {
        // Add bus to database
        $insert_query = "INSERT INTO buses (bus_type, seat_capacity, plate_number, origin, destination, driver_name, conductor_name, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sissssss", $bus_type, $seat_capacity, $plate_number, $origin, $destination, $driver_name, $conductor_name, $status);
        
        if ($stmt->execute()) {
            $bus_id = $conn->insert_id;
            $_SESSION['message'] = "Bus successfully registered.";
            $_SESSION['message_type'] = "success";
            
            // If status is Active, create default schedule
            if ($status == 'Active') {
                // Create a default schedule
                $departure_time = '08:00:00'; // Default departure time
                $arrival_time = '12:00:00';   // Default arrival time
                $fare_amount = 150.00;        // Default fare
                $recurring = 1;               // Make it recurring
                
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
        } else {
            $_SESSION['message'] = "Error registering bus: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect to prevent form resubmission
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
    $search_condition = " WHERE bus_type LIKE '%$search%' OR plate_number LIKE '%$search%' OR origin LIKE '%$search%' OR destination LIKE '%$search%' OR driver_name LIKE '%$search%' OR conductor_name LIKE '%$search%'";
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
    $query = "SELECT b.id, b.bus_type, b.seat_capacity, b.plate_number, b.origin, b.destination, 
              b.driver_name, b.conductor_name, b.status, b.created_at,
              (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND booking_date >= CURDATE() AND booking_status = 'confirmed') as active_bookings,
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

        .seat {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            font-size: 0.9rem;
            font-weight: bold;
            color: white;
            transition: all 0.3s;
            margin: 5px;
            position: relative;
            border: 2px solid transparent;
        }
        
        .seat-map-container {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.1);
        }
        
        .seat.available {
            background-color: #28a745;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            cursor: default;
        }
        
        .seat.booked {
            background-color: #dc3545;
            opacity: 0.8;
            cursor: default;
        }
        
        .seat-row {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
            gap: 10px;
            align-items: center;
        }
        
        .aisle {
            width: 20px;
            height: 40px;
        }
        
        .driver-area {
            max-width: 180px;
            margin: 0 auto 20px;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 8px;
            border: 1px dashed #adb5bd;
            font-weight: bold;
        }
        
        .seat-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 10px;
            background-color: white;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .front-back-indicator {
            background-color: #6c757d;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            margin: 10px 0;
            display: inline-block;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .seat-row-label {
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #e9ecef;
            border-radius: 50%;
            font-weight: bold;
            color: #495057;
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
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header d-flex align-items-center">
                <i class="fas fa-bus-alt me-2 fs-4"></i>
                <h4 class="mb-0">Admin Panel</h4>
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
                    <a class="nav-link" href="reports_admin.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="announcements_admin.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings_admin.php">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
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
                        <ul class="navbar-nav ms-3">
                            <li class="nav-item dropdown profile-section">
                                <a class="nav-link dropdown-toggle" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <img src="https://via.placeholder.com/40" alt="Admin">
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                                    <li><h6 class="dropdown-header">Admin User</h6></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="index.php"><i class="fas fa-sign-out-alt me-2"></i>Exit Admin</a></li>
                                </ul>
                            </li>
                        </ul>
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
                                        <td>#<?php echo $bus['id']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bus-icon me-2">
                                                    <i class="fas fa-bus"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold">Bus #<?php echo $bus['id']; ?></div>
                                                    <small><?php echo $bus['active_bookings']; ?> active bookings</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($bus['bus_type'] == 'Aircondition'): ?>
                                                <span class="badge bg-info text-dark">
                                                    <i class="fas fa-snowflake me-1"></i> Aircon
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <i class="fas fa-bus me-1"></i> Regular
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($bus['plate_number']); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($bus['origin']); ?> 
                                                <i class="fas fa-arrow-right mx-1"></i> 
                                                <?php echo htmlspecialchars($bus['destination']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $bus['seat_capacity']; ?> seats</td>
                                        <td>
                                            <small>
                                                <strong>Driver:</strong> <?php echo htmlspecialchars($bus['driver_name']); ?><br>
                                                <strong>Conductor:</strong> <?php echo htmlspecialchars($bus['conductor_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($bus['status'] == 'Active'): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle me-1"></i> Active
                                                </span>
                                                <div class="small text-success mt-1">Travel dates enabled</div>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-tools me-1"></i> Under Maintenance
                                                </span>
                                                <div class="small text-muted mt-1">Travel dates disabled</div>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-link toggle-status p-0 ms-1" data-id="<?php echo $bus['id']; ?>" data-current-status="<?php echo $bus['status']; ?>">
                                                <i class="fas fa-exchange-alt" data-bs-toggle="tooltip" title="Toggle Status"></i>
                                            </button>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($bus['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-outline-primary btn-sm view-bus" data-id="<?php echo $bus['id']; ?>" data-bs-toggle="tooltip" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-warning btn-sm edit-bus" data-id="<?php echo $bus['id']; ?>" data-bs-toggle="tooltip" title="Edit Bus">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm delete-bus" data-id="<?php echo $bus['id']; ?>" data-bs-toggle="tooltip" title="Delete Bus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                        
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No buses found. 
                            <?php if (!empty($search)): ?>
                            Try a different search term or <a href="buses_admin.php" class="alert-link">clear the search</a>.
                            <?php else: ?>
                            Click on "Register New Bus" to add your first bus.
                            <?php endif; ?>
                        </div>
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
                <div class="modal-header">
                    <h5 class="modal-title" id="addBusModalLabel"><i class="fas fa-bus-alt me-2"></i>Register New Bus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addBusForm" method="post" action="buses_admin.php">
                        <input type="hidden" name="action" value="add_bus">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="busType" class="form-label">Bus Type*</label>
                                <select class="form-select" id="busType" name="bus_type" required>
                                    <option value="" selected disabled>Select Bus Type</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Aircondition">Air-conditioned</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="seatCapacity" class="form-label">Seat Capacity*</label>
                                <input type="number" class="form-control" id="seatCapacity" name="seat_capacity" min="1" max="100" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="plateNumber" class="form-label">Plate Number*</label>
                            <input type="text" class="form-control" id="plateNumber" name="plate_number" required>
                            <div class="form-text">Enter the complete plate number of the bus</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="origin" class="form-label">Origin (From)*</label>
                                <select class="form-select" id="origin" name="origin" required>
                                    <option value="" selected disabled>Select Origin</option>
                                    <option value="Iloilo City">Iloilo City</option>
                                    <option value="Bacolod City">Bacolod City</option>
                                    <option value="Kalibo">Kalibo</option>
                                    <option value="Roxas City">Roxas City</option>
                                    <option value="San Jose de Buenavista">San Jose de Buenavista</option>
                                    <option value="Boracay">Boracay</option>
                                    <option value="Silay City">Silay City</option>
                                    <option value="Kabankalan City">Kabankalan City</option>
                                    <option value="Passi City">Passi City</option>
                                    <option value="Jordan">Jordan</option>
                                    <option value="Escalante City">Escalante City</option>
                                    <option value="Sagay City">Sagay City</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="destination" class="form-label">Destination (To)*</label>
                                <select class="form-select" id="destination" name="destination" required>
                                    <option value="" selected disabled>Select Destination</option>
                                    <option value="Iloilo City">Iloilo City</option>
                                    <option value="Bacolod City">Bacolod City</option>
                                    <option value="Kalibo">Kalibo</option>
                                    <option value="Roxas City">Roxas City</option>
                                    <option value="San Jose de Buenavista">San Jose de Buenavista</option>
                                    <option value="Boracay">Boracay</option>
                                    <option value="Silay City">Silay City</option>
                                    <option value="Kabankalan City">Kabankalan City</option>
                                    <option value="Passi City">Passi City</option>
                                    <option value="Jordan">Jordan</option>
                                    <option value="Escalante City">Escalante City</option>
                                    <option value="Sagay City">Sagay City</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="driverName" class="form-label">Driver Name*</label>
                                <input type="text" class="form-control" id="driverName" name="driver_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="conductorName" class="form-label">Conductor Name*</label>
                                <input type="text" class="form-control" id="conductorName" name="conductor_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="busStatus" class="form-label">Status*</label>
                            <select class="form-select" id="busStatus" name="status" required>
                                <option value="Active" selected>Active</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                            <div class="form-text">Choose the operational status of the bus. <strong>Note:</strong> Active buses will have travel dates enabled for booking.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addBusForm" class="btn btn-primary">Register Bus</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Bus Modal with Seat Map -->
    <div class="modal fade" id="viewBusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bus me-2"></i>Bus Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                                </div>
                                <div class="card-body" id="busBasicInfo">
                                    <!-- Bus info will be loaded here -->
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-route me-2"></i>Route & Staff</h6>
                                </div>
                                <div class="card-body" id="busRouteStaffInfo">
                                    <!-- Route and staff info will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Seat Map Section -->
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="fas fa-chair me-2"></i>Seat Map</h6>
                            <div class="d-flex align-items-center">
                                <input type="date" id="seatMapDatePicker" class="form-control form-control-sm me-2" style="width: 150px;">
                                <div class="seat-legend">
                                    <div class="legend-item">
                                        <div class="seat available" style="width: 25px; height: 25px;"></div>
                                        <span>Available</span>
                                    </div>
                                    <div class="legend-item">
                                        <div class="seat booked" style="width: 25px; height: 25px;"></div>
                                        <span>Booked</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="driver-area">
                                    <i class="fas fa-steering-wheel me-1"></i> Driver Area
                                </div>
                                <div class="front-back-indicator">
                                    <i class="fas fa-arrow-up me-1"></i> Front of Bus
                                </div>
                            </div>
                            
                            <div class="seat-map-container">
                                <div id="seatMapContainer" class="d-flex flex-column align-items-center justify-content-center">
                                    <!-- Seat map will be dynamically loaded here -->
                                    <div class="spinner-border text-primary mb-3" role="status">
                                        <span class="visually-hidden">Loading seats...</span>
                                    </div>
                                    <p>Loading seat map for next available date...</p>
                                </div>
                            </div>
                            
                            <div class="text-center mb-2">
                                <div class="front-back-indicator">
                                    <i class="fas fa-arrow-down me-1"></i> Back of Bus
                                </div>
                            </div>
                            
                            <div class="seat-status-card p-3">
                                <div class="row align-items-center text-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <div class="seat-counter bg-success text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                <span id="availableSeatCount">0</span>
                                            </div>
                                            <div>
                                                <span class="d-block fw-bold">Available Seats</span>
                                                <small class="text-muted" id="availableSeatDate"></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <div class="seat-counter bg-danger text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                <span id="bookedSeatCount">0</span>
                                            </div>
                                            <div>
                                                <span class="d-block fw-bold">Booked Seats</span>
                                                <small class="text-muted" id="bookedSeatDate"></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <div class="seat-counter bg-primary text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                <span id="totalSeatCount">0</span>
                                            </div>
                                            <div>
                                                <span class="d-block fw-bold">Total Seats</span>
                                                <small class="text-muted">Bus Capacity</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Schedule & Travel Dates</h6>
                            <a href="schedules_admin.php" class="btn btn-sm btn-outline-primary" id="viewSchedulesBtn">
                                Manage Schedules
                            </a>
                        </div>
                        <div class="card-body" id="busScheduleInfo">
                            <!-- Schedule info will be loaded here -->
                            <div class="alert alert-info mb-0" id="scheduleStatusInfo">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="fas fa-info-circle fa-2x text-info"></i>
                                    </div>
                                    <div>
                                        <h6 class="alert-heading mb-1">Travel Date Status</h6>
                                        <p class="mb-0" id="travel-date-message">Loading travel date status...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="toggleStatusBtn">
                        <i class="fas fa-exchange-alt me-1"></i>
                        <span id="toggleStatusText">Toggle Status</span>
                    </button>
                    <button type="button" class="btn btn-primary" id="editBusBtn">Edit Bus</button>
                </div>
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
                        <input type="hidden" name="action" value="edit_bus">
                        <input type="hidden" name="bus_id" id="editBusId">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="editBusType" class="form-label">Bus Type*</label>
                                <select class="form-select" id="editBusType" name="bus_type" required>
                                    <option value="Regular">Regular</option>
                                    <option value="Aircondition">Air-conditioned</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editSeatCapacity" class="form-label">Seat Capacity*</label>
                                <input type="number" class="form-control" id="editSeatCapacity" name="seat_capacity" min="1" max="100" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editPlateNumber" class="form-label">Plate Number*</label>
                            <input type="text" class="form-control" id="editPlateNumber" name="plate_number" required>
                            <div class="form-text">Enter the complete plate number of the bus</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="editOrigin" class="form-label">Origin (From)*</label>
                                <select class="form-select" id="editOrigin" name="origin" required>
                                    <option value="" disabled>Select Origin</option>
                                    <option value="Iloilo City">Iloilo City</option>
                                    <option value="Bacolod City">Bacolod City</option>
                                    <option value="Kalibo">Kalibo</option>
                                    <option value="Roxas City">Roxas City</option>
                                    <option value="San Jose de Buenavista">San Jose de Buenavista</option>
                                    <option value="Boracay">Boracay</option>
                                    <option value="Silay City">Silay City</option>
                                    <option value="Kabankalan City">Kabankalan City</option>
                                    <option value="Passi City">Passi City</option>
                                    <option value="Jordan">Jordan</option>
                                    <option value="Escalante City">Escalante City</option>
                                    <option value="Sagay City">Sagay City</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editDestination" class="form-label">Destination (To)*</label>
                                <select class="form-select" id="editDestination" name="destination" required>
                                    <option value="" disabled>Select Destination</option>
                                    <option value="Iloilo City">Iloilo City</option>
                                    <option value="Bacolod City">Bacolod City</option>
                                    <option value="Kalibo">Kalibo</option>
                                    <option value="Roxas City">Roxas City</option>
                                    <option value="San Jose de Buenavista">San Jose de Buenavista</option>
                                    <option value="Boracay">Boracay</option>
                                    <option value="Silay City">Silay City</option>
                                    <option value="Kabankalan City">Kabankalan City</option>
                                    <option value="Passi City">Passi City</option>
                                    <option value="Jordan">Jordan</option>
                                    <option value="Escalante City">Escalante City</option>
                                    <option value="Sagay City">Sagay City</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="editDriverName" class="form-label">Driver Name*</label>
                                <input type="text" class="form-control" id="editDriverName" name="driver_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="editConductorName" class="form-label">Conductor Name*</label>
                                <input type="text" class="form-control" id="editConductorName" name="conductor_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editBusStatus" class="form-label">Status*</label>
                            <select class="form-select" id="editBusStatus" name="status" required>
                                <option value="Active">Active</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                            </select>
                            <div class="form-text">Choose the operational status of the bus. <strong>Note:</strong> Active buses will have travel dates enabled for booking.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editBusForm" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store buses data globally for easier access
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

        // Find bus by ID
        function findBusById(busId) {
            return busesData.find(bus => bus.id == busId) || {
                id: busId,
                bus_type: 'Regular',
                seat_capacity: 45,
                plate_number: 'ABC-1234',
                status: 'Active',
                origin: 'Iloilo City',
                destination: 'Bacolod City',
                driver_name: 'John Doe',
                conductor_name: 'Jane Smith',
                created_at: '<?php echo date('M d, Y'); ?>',
                active_bookings: 0,
                schedule_count: 0
            };
        }

        // Function to get the next available date (tomorrow by default)
        function getNextAvailableDate() {
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            return tomorrow.toISOString().split('T')[0];
        }

        // Helper function to format date
        function formatDate(dateString) {
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }

        // Toggle bus status functionality
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

        // View bus details functionality
        document.querySelectorAll('.view-bus').forEach(function(button) {
            button.addEventListener('click', function() {
                const busId = this.getAttribute('data-id');
                const bus = findBusById(busId);
                
                // Reset loading states
                document.getElementById('busBasicInfo').innerHTML = `
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Bus ID:</dt>
                        <dd class="col-sm-8">#${bus.id}</dd>
                        
                        <dt class="col-sm-4">Bus Type:</dt>
                        <dd class="col-sm-8">
                            ${bus.bus_type === 'Aircondition' ? 
                            '<span class="badge bg-info text-dark"><i class="fas fa-snowflake me-1"></i> Aircon</span>' : 
                            '<span class="badge bg-secondary"><i class="fas fa-bus me-1"></i> Regular</span>'}
                        </dd>
                        
                        <dt class="col-sm-4">Plate Number:</dt>
                        <dd class="col-sm-8">${bus.plate_number}</dd>
                        
                        <dt class="col-sm-4">Seat Capacity:</dt>
                        <dd class="col-sm-8">${bus.seat_capacity} seats</dd>
                        
                        <dt class="col-sm-4">Status:</dt>
                        <dd class="col-sm-8">
                            ${bus.status === 'Active' ? 
                            '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Active</span>' : 
                            '<span class="badge bg-warning text-dark"><i class="fas fa-tools me-1"></i> Under Maintenance</span>'}
                        </dd>
                    </dl>
                `;
                
                document.getElementById('busRouteStaffInfo').innerHTML = `
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Route:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-light text-dark">
                                ${bus.origin} 
                                <i class="fas fa-arrow-right mx-1"></i> 
                                ${bus.destination}
                            </span>
                        </dd>
                        
                        <dt class="col-sm-4">Driver:</dt>
                        <dd class="col-sm-8">${bus.driver_name}</dd>
                        
                        <dt class="col-sm-4">Conductor:</dt>
                        <dd class="col-sm-8">${bus.conductor_name}</dd>
                        
                        <dt class="col-sm-4">Registered On:</dt>
                        <dd class="col-sm-8">${bus.created_at}</dd>
                        
                        <dt class="col-sm-4">Bookings:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-primary">
                                <i class="fas fa-ticket-alt me-1"></i> ${bus.active_bookings}
                            </span>
                            active bookings
                        </dd>
                    </dl>
                `;

                // Toggle Status Button
                const toggleBtn = document.getElementById('toggleStatusBtn');
                if (bus.status === 'Active') {
                    toggleBtn.className = 'btn btn-warning';
                    toggleBtn.innerHTML = '<i class="fas fa-tools me-1"></i> Set to Maintenance';
                    
                    document.getElementById('travel-date-message').innerHTML = 
                        'This bus is <span class="badge bg-success">Active</span> and ' +
                        'travelers can select travel dates for booking. The bus has ' +
                        `<strong>${bus.schedule_count}</strong> schedule(s) and ` +
                        `<strong>${bus.active_bookings}</strong> active booking(s).`;
                } else {
                    toggleBtn.className = 'btn btn-success';
                    toggleBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Set to Active';
                    
                    document.getElementById('travel-date-message').innerHTML = 
                        'This bus is <span class="badge bg-warning text-dark">Under Maintenance</span> ' +
                        'and travelers cannot select travel dates for booking. ' +
                        'To enable bookings, change the status to Active.';
                }

                // Toggle status button click handler
                toggleBtn.onclick = function() {
                    const newStatus = bus.status === 'Active' ? 'Under Maintenance' : 'Active';
                    let message = `Are you sure you want to change the bus status from ${bus.status} to ${newStatus}?`;
                    
                    if (bus.status === 'Active') {
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
                        busIdInput.value = bus.id;
                        form.appendChild(busIdInput);
                        
                        const currentStatusInput = document.createElement('input');
                        currentStatusInput.type = 'hidden';
                        currentStatusInput.name = 'current_status';
                        currentStatusInput.value = bus.status;
                        form.appendChild(currentStatusInput);
                        
                        document.body.appendChild(form);
                        form.submit();
                    }
                };

                // Update View Schedules button
                document.getElementById('viewSchedulesBtn').href = `schedules_admin.php?bus_id=${bus.id}`;

                // Initialize date picker with tomorrow's date
                const datePicker = document.getElementById('seatMapDatePicker');
                const nextAvailableDate = getNextAvailableDate();
                datePicker.value = nextAvailableDate;
                datePicker.min = new Date().toISOString().split('T')[0];
                
                // Generate seat map for the next available date
                generateSeatMap(bus.id, parseInt(bus.seat_capacity), nextAvailableDate);
                
                // Update date display in counters
                document.getElementById('availableSeatDate').textContent = formatDate(nextAvailableDate);
                document.getElementById('bookedSeatDate').textContent = formatDate(nextAvailableDate);
                
                // Add event listener for date changes
                datePicker.addEventListener('change', function() {
                    const selectedDate = this.value;
                    generateSeatMap(bus.id, parseInt(bus.seat_capacity), selectedDate);
                    document.getElementById('availableSeatDate').textContent = formatDate(selectedDate);
                    document.getElementById('bookedSeatDate').textContent = formatDate(selectedDate);
                });

                // Edit Bus Button
                document.getElementById('editBusBtn').onclick = function() {
                    // Hide view modal
                    var viewBusModal = bootstrap.Modal.getInstance(document.getElementById('viewBusModal'));
                    viewBusModal.hide();
                    
                    // Populate edit modal
                    document.getElementById('editBusId').value = bus.id;
                    document.getElementById('editBusType').value = bus.bus_type;
                    document.getElementById('editSeatCapacity').value = bus.seat_capacity;
                    document.getElementById('editPlateNumber').value = bus.plate_number;
                    document.getElementById('editBusStatus').value = bus.status;
                    document.getElementById('editOrigin').value = bus.origin;
                    document.getElementById('editDestination').value = bus.destination;
                    document.getElementById('editDriverName').value = bus.driver_name;
                    document.getElementById('editConductorName').value = bus.conductor_name;
                    
                    // Show edit modal
                    var editBusModal = new bootstrap.Modal(document.getElementById('editBusModal'));
                    editBusModal.show();
                };

                // Show the modal
                var viewBusModal = new bootstrap.Modal(document.getElementById('viewBusModal'));
                viewBusModal.show();
            });
        });

        // Edit bus directly
        document.querySelectorAll('.edit-bus').forEach(function(button) {
            button.addEventListener('click', function() {
                const busId = this.getAttribute('data-id');
                const bus = findBusById(busId);
                
                // Populate edit modal
                document.getElementById('editBusId').value = bus.id;
                document.getElementById('editBusType').value = bus.bus_type;
                document.getElementById('editSeatCapacity').value = bus.seat_capacity;
                document.getElementById('editPlateNumber').value = bus.plate_number;
                document.getElementById('editBusStatus').value = bus.status;
                
                // Set origin value
                const originSelect = document.getElementById('editOrigin');
                originSelect.value = bus.origin;
                
                // Set destination value - do this BEFORE disabling options
                const destinationSelect = document.getElementById('editDestination');
                destinationSelect.value = bus.destination;
                
                // Now disable the origin option in destination
                if (bus.origin) {
                    for (let i = 0; i < destinationSelect.options.length; i++) {
                        destinationSelect.options[i].disabled = (destinationSelect.options[i].value === bus.origin);
                    }
                }
                
                document.getElementById('editDriverName').value = bus.driver_name;
                document.getElementById('editConductorName').value = bus.conductor_name;
                
                // Show edit modal
                var editBusModal = new bootstrap.Modal(document.getElementById('editBusModal'));
                editBusModal.show();
            });
        });

        // Functions for generating seat map
        function generateSeatMap(busId, totalSeats, selectedDate) {
            const seatMapContainer = document.getElementById('seatMapContainer');
            seatMapContainer.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
            
            fetchBookedSeats(busId, selectedDate).then(bookedSeats => {
                seatMapContainer.innerHTML = '';
                
                let bookedCount = 0;
                let seatNumber = 1;
                const seatsPerRow = 4; // 2 left + 2 right
                const backRowSeats = 5; // last row is 5 seats straight

                const normalSeats = totalSeats - backRowSeats;
                const rowCount = Math.floor(normalSeats / seatsPerRow);

                // Create rows before back row
                for (let row = 0; row < rowCount; row++) {
                    const rowDiv = document.createElement('div');
                    rowDiv.className = 'seat-row';

                    // Left side (2 seats)
                    for (let i = 0; i < 2; i++) {
                        if (seatNumber <= normalSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            if (isBooked) bookedCount++;
                            seatNumber++;
                        }
                    }

                    // Aisle space
                    const aisleDiv = document.createElement('div');
                    aisleDiv.className = 'aisle';
                    rowDiv.appendChild(aisleDiv);

                    // Right side (2 seats)
                    for (let i = 0; i < 2; i++) {
                        if (seatNumber <= normalSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            if (isBooked) bookedCount++;
                            seatNumber++;
                        }
                    }

                    seatMapContainer.appendChild(rowDiv);
                }

                // Back row
                const backRowDiv = document.createElement('div');
                backRowDiv.className = 'seat-row back-row';
                for (let i = 0; i < backRowSeats; i++) {
                    if (seatNumber <= totalSeats) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        backRowDiv.appendChild(seat);
                        if (isBooked) bookedCount++;
                        seatNumber++;
                    }
                }
                seatMapContainer.appendChild(backRowDiv);

                // Update counters
                document.getElementById('bookedSeatCount').textContent = bookedCount;
                document.getElementById('totalSeatCount').textContent = totalSeats;
                document.getElementById('availableSeatCount').textContent = totalSeats - bookedCount;
                
                // Add date information
                const dateInfo = document.createElement('div');
                dateInfo.className = 'text-center mt-3';
                dateInfo.innerHTML = `
                    <p class="mb-0">
                        <strong>Seat availability for ${formatDate(selectedDate)}</strong>
                    </p>
                    <p class="small text-muted mb-0">
                        ${bookedCount} seats booked, ${totalSeats - bookedCount} seats available
                    </p>
                `;
                seatMapContainer.appendChild(dateInfo);
            });
        }

        function createSeatElement(seatNumber, isBooked) {
            const seat = document.createElement('div');
            seat.className = `seat ${isBooked ? 'booked' : 'available'}`;
            seat.dataset.seatNumber = seatNumber;
            seat.textContent = seatNumber;
            
            // Add tooltip
            seat.title = `Seat ${seatNumber}: ${isBooked ? 'Booked' : 'Available'}`;
            
            return seat;
        }

        // Function to fetch booked seats from the database
        function fetchBookedSeats(busId, date = null) {
            let url = `../../backend/connections/get_booked_seats.php?bus_id=${busId}`;
            if (date) {
                url += `&date=${date}`;
            }
            
            return fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    return data.bookedSeats || [];
                })
                .catch(error => {
                    console.error('Error fetching booked seats:', error);
                    return [];
                });
        }

        // Prevent selecting same origin and destination in add bus form
        document.getElementById('origin').addEventListener('change', function() {
            const destination = document.getElementById('destination');
            const selectedValue = this.value;
            
            // Reset any previous error states
            this.classList.remove('is-invalid');
            destination.classList.remove('is-invalid');
            const errorElement = document.getElementById('routeError');
            if (errorElement) errorElement.remove();
            
            // Enable all options
            for (let i = 0; i < destination.options.length; i++) {
                destination.options[i].disabled = false;
            }
            
            // Disable matching option in destination
            if (selectedValue) {
                for (let i = 0; i < destination.options.length; i++) {
                    if (destination.options[i].value === selectedValue) {
                        destination.options[i].disabled = true;
                        
                        // If currently selected option is now disabled, reset selection
                        if (destination.value === selectedValue) {
                            destination.value = '';
                        }
                        break;
                    }
                }
            }
        });

        // Prevent selecting same origin and destination in edit bus form
        document.getElementById('editOrigin').addEventListener('change', function() {
            const destination = document.getElementById('editDestination');
            const selectedValue = this.value;
            
            // Reset any previous error states
            this.classList.remove('is-invalid');
            destination.classList.remove('is-invalid');
            const errorElement = document.getElementById('editRouteError');
            if (errorElement) errorElement.remove();
            
            // Enable all options
            for (let i = 0; i < destination.options.length; i++) {
                destination.options[i].disabled = false;
            }
            
            // Disable matching option in destination
            if (selectedValue) {
                for (let i = 0; i < destination.options.length; i++) {
                    if (destination.options[i].value === selectedValue) {
                        destination.options[i].disabled = true;
                        
                        // If currently selected option is now disabled, reset selection
                        if (destination.value === selectedValue) {
                            destination.value = '';
                        }
                        break;
                    }
                }
            }
        });

    
    </script>
</body>
</html>