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

// Handle date filter
$today = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today;

// Handle origin and destination filters
$origin_filter = isset($_GET['origin']) ? $_GET['origin'] : '';
$destination_filter = isset($_GET['destination']) ? $_GET['destination'] : '';

// Fetch ALL available origins and destinations for filters
$origins = [];
$destinations = [];
try {
    // Get origins and destinations from the route_name field in buses table
    $query = "SELECT DISTINCT 
                SUBSTRING_INDEX(route_name, ' → ', 1) as origin 
              FROM 
                buses 
              ORDER BY 
                origin";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['origin'])) {
                $origins[] = $row['origin'];
            }
        }
    }
    
    $query = "SELECT DISTINCT 
                SUBSTRING_INDEX(route_name, ' → ', -1) as destination 
              FROM 
                buses 
              ORDER BY 
                destination";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['destination'])) {
                $destinations[] = $row['destination'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching filter data: " . $e->getMessage());
}

// Get buses with maintenance status
$maintenance_buses = [];
try {
    $query = "SELECT id, bus_type, seat_capacity, plate_number, driver_name, conductor_name, route_name 
              FROM buses 
              WHERE status = 'Under Maintenance'";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $maintenance_buses[$row['id']] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching maintenance buses: " . $e->getMessage());
}

// Get buses with inactive status
$inactive_buses = [];
try {
    $query = "SELECT id, bus_type, seat_capacity, plate_number, driver_name, conductor_name, route_name 
              FROM buses 
              WHERE status = 'Inactive'";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $inactive_buses[$row['id']] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching inactive buses: " . $e->getMessage());
}

// Count inactive buses
$inactive_buses_count = count($inactive_buses);

// Get active buses - this is what we'll display
$active_buses = [];
try {
    // Start with a basic query
    $query = "SELECT id, bus_type, seat_capacity, plate_number, driver_name, conductor_name, route_name, status 
              FROM buses 
              WHERE status = 'Active'";
              
    // Build query based on filters
    $params = [];
    $types = "";
    
    // Add origin filter if selected
    if (!empty($origin_filter) && !empty($destination_filter)) {
        // If both origin and destination are provided, filter for exact route
        $query .= " AND route_name = ?";
        $params[] = $origin_filter . " → " . $destination_filter;
        $types .= "s";
    } else {
        // Otherwise, filter for partial matches
        if (!empty($origin_filter)) {
            $query .= " AND route_name LIKE ?";
            $params[] = $origin_filter . " → %";
            $types .= "s";
        }
        
        if (!empty($destination_filter)) {
            $query .= " AND route_name LIKE ?";
            $params[] = "% → " . $destination_filter;
            $types .= "s";
        }
    }
    
    $query .= " ORDER BY id";
    
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        // Only bind params if we have any
        if (!empty($params)) {
            // Create the parameter binding arguments
            $bind_params = array($types);
            foreach ($params as $key => $value) {
                $bind_params[] = &$params[$key];
            }
            
            // Call bind_param with the unpacked array
            call_user_func_array(array($stmt, 'bind_param'), $bind_params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $active_buses[$row['id']] = $row;
                
                // Extract origin and destination from route_name
                if (!empty($row['route_name']) && strpos($row['route_name'], ' → ') !== false) {
                    $parts = explode(' → ', $row['route_name']);
                    $active_buses[$row['id']]['origin'] = $parts[0];
                    $active_buses[$row['id']]['destination'] = $parts[1];
                } else {
                    $active_buses[$row['id']]['origin'] = '';
                    $active_buses[$row['id']]['destination'] = '';
                }
            }
        }
        
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching active buses: " . $e->getMessage());
}

// Get route information for display
$routes_info = [];
try {
    $query = "SELECT origin, destination, distance, estimated_duration, fare FROM routes";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $route_key = strtolower($row['origin'] . '→' . $row['destination']);
            $routes_info[$route_key] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching routes info: " . $e->getMessage());
}

// Get schedules for the selected date including trip numbers
$schedules_by_bus = [];
$buses_with_schedules = []; // Track which buses have schedules
try {
    // Modified query to include trip_number
    $query = "SELECT s.id as schedule_id, s.bus_id, s.origin, s.destination, 
                 s.departure_time, s.arrival_time, s.recurring, 
                 s.fare_amount, s.trip_number, s.status
          FROM schedules s 
          WHERE 
              (s.recurring = 1 OR 
               (s.recurring = 0 AND s.date = ?)) 
              AND s.status = 'active'";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $selected_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $bus_id = $row['bus_id'];
                $buses_with_schedules[] = $bus_id; // Mark this bus as having a schedule
                
                // Format times
                $row['formatted_departure'] = date('h:i A', strtotime($row['departure_time']));
                $row['formatted_arrival'] = date('h:i A', strtotime($row['arrival_time']));
                
                if (!isset($schedules_by_bus[$bus_id])) {
                    $schedules_by_bus[$bus_id] = [];
                }
                $schedules_by_bus[$bus_id][] = $row;
            }
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching schedules by bus: " . $e->getMessage());
}

// Filter out active buses that don't have any schedules
foreach ($active_buses as $bus_id => $bus) {
    if (!isset($schedules_by_bus[$bus_id])) {
        unset($active_buses[$bus_id]);
    }
}

// Get the next 7 days for date navigation
$next_dates = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($today . ' + ' . $i . ' days'));
    $next_dates[] = [
        'date' => $date,
        'display' => date('D, M d', strtotime($date)),
        'is_today' => $date == $today
    ];
}

// Update counts after filtering
$active_buses_count = count($active_buses);
$maintenance_buses_count = count($maintenance_buses);

// Function to calculate arrival time based on departure time and duration
function calculateArrivalTime($departureTime, $duration) {
    // Parse duration format like "2h 30m" or "1h"
    preg_match('/(\d+)h(?:\s*(\d+)m)?/', $duration, $matches);
    
    $hours = isset($matches[1]) ? (int)$matches[1] : 0;
    $minutes = isset($matches[2]) ? (int)$matches[2] : 0;
    
    $totalMinutes = ($hours * 60) + $minutes;
    
    // Calculate arrival time
    $departureTimestamp = strtotime($departureTime);
    $arrivalTimestamp = $departureTimestamp + ($totalMinutes * 60);
    
    return date('h:i A', $arrivalTimestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Schedules - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">   
    <style>
        .date-nav {
            display: flex;
            overflow-x: auto;
            gap: 10px;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .date-nav::-webkit-scrollbar {
            height: 4px;
        }
        
        .date-nav::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .date-nav::-webkit-scrollbar-thumb {
            background: #ffc107;
            border-radius: 4px;
        }
        
        .date-item {
            flex: 0 0 auto;
            padding: 10px 15px;
            border-radius: 8px;
            background-color: #f8f9fa;
            cursor: pointer;
            text-align: center;
            border: 1px solid transparent;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .date-item:hover {
            border-color: #ffc107;
        }
        
        .date-item.active {
            background-color: #ffc107;
            color: #212529;
            font-weight: 600;
        }
        
        .schedule-card {
            border: 1px solid #ccc; 
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .schedule-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-time {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            position: relative;
        }
        
        .schedule-time::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 15%;
            right: 15%;
            height: 2px;
            background-color: #e9ecef;
            z-index: 1;
        }
        
        .time-point {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #ffc107;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #212529;
            font-weight: 600;
            z-index: 2;
            position: relative;
        }
        
        .departure h5, .arrival h5 {
            margin-bottom: 5px;
        }
        
        .departure p, .arrival p {
            margin-bottom: 0;
            color: #6c757d;
        }
        
        .schedule-info {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .schedule-info i {
            width: 24px;
            margin-right: 10px;
            color: #ffc107;
        }
        
        .schedule-actions {
            display: flex;
            gap: 10px;
        }
        
        .bus-badge {
            background-color: #ffc107;
            color: #212529;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 5px 15px;
            border-radius: 20px;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .booking-cta {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
            font-weight: 500;
        }
        
        .booking-cta:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            color: #212529;
        }
        
        .filter-row {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-badge.daily {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .status-badge.one-time {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .no-schedule {
            padding: 40px 0;
            text-align: center;
        }
        
        .no-schedule i {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .no-schedule h5 {
            margin-bottom: 15px;
            color: #343a40;
        }
        
        .status-summary {
            background-color: #fff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .status-summary .status-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-right: 15px;
            font-size: 1.5rem;
        }
        
        .status-summary .active-icon {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .status-summary .maintenance-icon {
            background-color: #f8d7da;
            color: #842029;
        }
        
        .status-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .status-label {
            color: #6c757d;
            margin-bottom: 0;
        }
        
        /* New styles for trip numbers */
        .trip-badge {
            background-color: #e0f7fa;
            color: #0277bd;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 15px;
            margin-top: 5px;
            display: inline-block;
        }
        
        /* Trip filter */
        .trip-filter-item {
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 15px;
            margin-right: 5px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
        }
        
        .trip-filter-item:hover {
            background-color: #e9ecef;
        }
        
        .trip-filter-item.active {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }

        .status-summary .inactive-icon {
            background-color: #e9ecef;
            color: #495057;
        }
        
        /* Responsive adjustments */
        @media (max-width: 767.98px) {
            .departure, .arrival {
                text-align: center;
            }
            
            .schedule-time::before {
                left: 5%;
                right: 5%;
            }
            
            .schedule-actions {
                flex-direction: column;
                gap: 5px;
            }
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
                        <a class="nav-link active" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="booking.php">Book Ticket</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="locations.php">Locations</a>
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
                        <h4 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Bus Schedules</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i> View all available Ceres bus schedules connecting ISAT-U campuses and key locations. <strong>Note: Only active buses are shown in the schedule list. Buses under maintenance are not available for booking.</strong>
                        </div>
                        
                        <!-- Bus Status Summary -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="status-summary d-flex align-items-center">
                                    <div class="status-icon active-icon">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div>
                                        <p class="status-count"><?php echo $active_buses_count; ?></p>
                                        <p class="status-label">Active Buses</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="status-summary d-flex align-items-center">
                                    <div class="status-icon maintenance-icon">
                                        <i class="fas fa-tools"></i>
                                    </div>
                                    <div>
                                        <p class="status-count"><?php echo $maintenance_buses_count; ?></p>
                                        <p class="status-label">Buses Under Maintenance</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="status-summary d-flex align-items-center">
                                    <div class="status-icon inactive-icon">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                    <div>
                                        <p class="status-count"><?php echo $inactive_buses_count; ?></p>
                                        <p class="status-label">Inactive Buses</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Date Navigation -->
                        <h5 class="mb-3"><i class="fas fa-calendar-day me-2"></i>Select Date</h5>
                        <div class="date-nav">
                            <?php foreach ($next_dates as $date_item): ?>
                                <a href="?date=<?php echo $date_item['date']; ?><?php echo !empty($origin_filter) ? '&origin=' . urlencode($origin_filter) : ''; ?><?php echo !empty($destination_filter) ? '&destination=' . urlencode($destination_filter) : ''; ?>" 
                                   class="date-item <?php echo $date_item['date'] == $selected_date ? 'active' : ''; ?>">
                                    <?php if ($date_item['is_today']): ?>
                                        <small>Today</small><br>
                                    <?php endif; ?>
                                    <?php echo $date_item['display']; ?>
                                </a>
                            <?php endforeach; ?>
                            
                            <!-- Custom date selector -->
                            <div class="date-item">
                                <form id="custom-date-form" method="get" action="">
                                    <small>Custom</small><br>
                                    <input type="date" id="custom-date" name="date" class="form-control form-control-sm" value="<?php echo $selected_date; ?>" onchange="document.getElementById('custom-date-form').submit()">
                                    <?php if (!empty($origin_filter)): ?>
                                        <input type="hidden" name="origin" value="<?php echo htmlspecialchars($origin_filter); ?>">
                                    <?php endif; ?>
                                    <?php if (!empty($destination_filter)): ?>
                                        <input type="hidden" name="destination" value="<?php echo htmlspecialchars($destination_filter); ?>">
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Route Filters -->
                        <div class="filter-row">
                            <form method="get" id="filter-form" action="">
                                <div class="row">
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <label for="origin" class="form-label"><i class="fas fa-map-marker-alt me-2"></i>Origin</label>
                                        <select class="form-select" id="origin" name="origin" onchange="document.getElementById('filter-form').submit()">
                                            <option value="">All Origins</option>
                                            <?php foreach ($origins as $origin): ?>
                                                <option value="<?php echo htmlspecialchars($origin); ?>" <?php echo $origin_filter == $origin ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst(htmlspecialchars($origin)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <label for="destination" class="form-label"><i class="fas fa-map-pin me-2"></i>Destination</label>
                                        <select class="form-select" id="destination" name="destination" onchange="document.getElementById('filter-form').submit()">
                                            <option value="">All Destinations</option>
                                            <?php foreach ($destinations as $destination): ?>
                                                <option value="<?php echo htmlspecialchars($destination); ?>" <?php echo $destination_filter == $destination ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst(htmlspecialchars($destination)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-4 d-flex align-items-end">
                                        <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                                        <button type="button" class="btn btn-outline-secondary w-100" onclick="window.location.href='schedule.php?date=<?php echo $selected_date; ?>'">
                                            <i class="fas fa-sync-alt me-2"></i>Reset Filters
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Today's Date Display -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5>
                                <i class="fas fa-calendar-check me-2"></i>
                                Schedule for <?php echo date('l, F d, Y', strtotime($selected_date)); ?>
                            </h5>
                            
                            <?php if (!empty($origin_filter) || !empty($destination_filter)): ?>
                                <div class="filter-summary">
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-filter me-1"></i>
                                        <?php
                                        $filter_parts = [];
                                        if (!empty($origin_filter)) {
                                            $filter_parts[] = 'From: ' . ucfirst($origin_filter);
                                        }
                                        if (!empty($destination_filter)) {
                                            $filter_parts[] = 'To: ' . ucfirst($destination_filter);
                                        }
                                        echo implode(' | ', $filter_parts);
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Trip Filter (New) -->
                        <div class="mb-4">
                            <h6><i class="fas fa-exchange-alt me-2"></i>Filter by Trip</h6>
                            <div class="d-flex flex-wrap mt-2" id="trip-filter">
                                <div class="trip-filter-item active" data-trip="all">All Trips</div>
                                <div class="trip-filter-item" data-trip="1st Trip">1st Trip</div>
                                <div class="trip-filter-item" data-trip="2nd Trip">2nd Trip</div>
                                <div class="trip-filter-item" data-trip="3rd Trip">3rd Trip</div>
                                <div class="trip-filter-item" data-trip="4th Trip">4th Trip</div>
                                <div class="trip-filter-item" data-trip="Special Trip">Special Trip</div>
                            </div>
                        </div>
                        
                        <!-- Schedule Cards - Display ACTIVE BUSES -->
                        <div class="row" id="schedule-cards">
                            <?php if (count($active_buses) > 0): ?>
                                <?php 
                                // For each active bus, display a schedule card
                                foreach ($active_buses as $bus_id => $bus): 
                                    // Get route information if available
                                    $route_key = strtolower($bus['origin'] . '→' . $bus['destination']);
                                    $route_info = isset($routes_info[$route_key]) ? $routes_info[$route_key] : null;
                                    
                                    // Get estimated duration for calculating arrival time
                                    $estimated_duration = isset($route_info['estimated_duration']) ? $route_info['estimated_duration'] : '2h 30m';
                                    
                                    // Get schedule information if available
                                    $bus_schedules = isset($schedules_by_bus[$bus_id]) ? $schedules_by_bus[$bus_id] : [];
                                    
                                    // Sort the schedules by departure time
                                    usort($bus_schedules, function($a, $b) {
                                        return strtotime($a['departure_time']) - strtotime($b['departure_time']);
                                    });
                                    
                                    // Show each time slot as a separate card
                                    foreach ($bus_schedules as $index => $schedule):
                                        // If trip_number is not set, assign default trip names (1st Trip, 2nd Trip, etc.)
                                        if (empty($schedule['trip_number'])) {
                                            // Generate trip name based on index (0-based)
                                            $trip_order = $index + 1;
                                            
                                            // Convert to ordinal (1st, 2nd, 3rd, etc.)
                                            $ordinals = ['1st', '2nd', '3rd', '4th', '5th'];
                                            $trip_name = isset($ordinals[$index]) ? $ordinals[$index] . ' Trip' : ($trip_order . 'th Trip');
                                            
                                            $schedule['trip_number'] = $trip_name;
                                        }
                                        
                                        // Ensure arrival time is calculated based on route duration if not provided
                                        if (empty($schedule['formatted_arrival'])) {
                                            $schedule['formatted_arrival'] = calculateArrivalTime($schedule['formatted_departure'], $estimated_duration);
                                        }
                                        
                                        // Set CSS class for trip filtering
                                        $trip_class = !empty($schedule['trip_number']) ? 'trip-' . str_replace(' ', '-', strtolower($schedule['trip_number'])) : '';
                                        
                                        // Get trip number for display
                                        $trip_number = !empty($schedule['trip_number']) ? $schedule['trip_number'] : 'Regular Trip';
                                ?>
                                    <div class="col-lg-6 schedule-item <?php echo $trip_class; ?>">
                                        <div class="card schedule-card">
                                            <div class="card-body">
                                                <!-- Bus Type Badge -->
                                                <span class="bus-badge">
                                                    <i class="fas fa-bus me-1"></i>
                                                    <?php echo htmlspecialchars($bus['bus_type']); ?> Bus
                                                </span>
                                                
                                                <!-- Trip Number Badge (NEW) -->
                                                <span class="trip-badge float-end">
                                                    <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($trip_number); ?>
                                                </span>
                                                
                                                <!-- Bus Status Badge -->
                                                <span class="badge bg-success me-2 float-end">
                                                    <i class="fas fa-check-circle me-1"></i>Active
                                                </span>
                                                
                                                <!-- Schedule Type -->
                                                <?php if (isset($schedule['recurring']) && $schedule['recurring'] == 1): ?>
                                                    <span class="status-badge daily">
                                                        <i class="fas fa-sync-alt me-1"></i>Daily
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge one-time">
                                                        <i class="fas fa-calendar-day me-1"></i>One-time
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <!-- Route Display -->
                                                <h5 class="route-title mt-3 mb-3">
                                                    <?php echo ucfirst(htmlspecialchars($bus['origin'])); ?> to <?php echo ucfirst(htmlspecialchars($bus['destination'])); ?>
                                                </h5>
                                                
                                                <!-- Departure and Arrival Times -->
                                                <div class="schedule-time">
                                                    <div class="departure text-center">
                                                        <h5><?php echo htmlspecialchars($schedule['formatted_departure']); ?></h5>
                                                        <p><?php echo ucfirst(htmlspecialchars($bus['origin'])); ?></p>
                                                        <small class="text-success">
                                                            <i class="fas fa-clock me-1"></i>Departs
                                                        </small>
                                                    </div>
                                                    
                                                    <div class="time-point">
                                                        <i class="fas fa-arrow-right"></i>
                                                    </div>
                                                    
                                                    <div class="arrival text-center">
                                                        <h5><?php echo htmlspecialchars($schedule['formatted_arrival']); ?></h5>
                                                        <p><?php echo ucfirst(htmlspecialchars($bus['destination'])); ?></p>
                                                        <small class="text-success">
                                                            <i class="fas fa-clock me-1"></i>Arrives
                                                        </small>
                                                    </div>
                                                </div>
                                                
                                                <!-- Additional Schedule Info -->
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <div class="schedule-info">
                                                            <i class="fas fa-clock"></i>
                                                            <span><strong>Duration:</strong> <?php echo htmlspecialchars($estimated_duration); ?></span>
                                                        </div>
                                                        
                                                        <div class="schedule-info">
                                                            <i class="fas fa-money-bill-wave"></i>
                                                            <span><strong>Fare:</strong> ₱<?php 
                                                                $fare = isset($route_info['fare']) ? $route_info['fare'] : 
                                                                    (isset($schedule['fare_amount']) ? $schedule['fare_amount'] : 150.00);
                                                                echo number_format($fare, 2); 
                                                            ?></span>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                        <div class="schedule-info">
                                                            <i class="fas fa-id-card"></i>
                                                            <span><strong>Bus #:</strong> <?php echo htmlspecialchars($bus['plate_number']); ?></span>
                                                        </div>
                                                        
                                                        <div class="schedule-info">
                                                            <i class="fas fa-calendar-alt"></i>
                                                            <span><strong>Schedule:</strong> <?php echo isset($schedule['recurring']) && $schedule['recurring'] == 1 ? 'Daily' : 'One-time'; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Book Button -->
                                                <a href="booking.php?origin=<?php echo urlencode($bus['origin']); ?>&destination=<?php echo urlencode($bus['destination']); ?>&date=<?php echo $selected_date; ?>&bus_id=<?php echo $bus_id; ?>&trip=<?php echo urlencode($schedule['trip_number']); ?>&schedule_id=<?php echo $schedule['schedule_id']; ?>" class="btn booking-cta">
                                                    <i class="fas fa-ticket-alt me-2"></i>Book This Bus
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach; // End schedule loop
                                endforeach; // End active buses loop
                                ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="no-schedule">
                                        <i class="fas fa-calendar-times"></i>
                                        <h5>No active schedules found for the selected criteria</h5>
                                        <p class="text-muted">Try selecting a different route or date, or check back later for updated bus schedules.</p>
                                        
                                        <?php if (!empty($origin_filter) || !empty($destination_filter)): ?>
                                            <a href="schedule.php?date=<?php echo $selected_date; ?>" class="btn btn-outline-warning">
                                                <i class="fas fa-sync-alt me-2"></i>Reset Filters
                                            </a>
                                        <?php else: ?>
                                            <a href="schedule.php" class="btn btn-outline-warning">
                                                <i class="fas fa-calendar-alt me-2"></i>View Today's Schedule
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Maintenance Buses Information (if any) -->
                        <?php if (count($maintenance_buses) > 0): ?>
                        <div class="mt-4">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-tools me-2"></i>Buses Currently Under Maintenance</h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-warning" role="alert">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        The following buses are currently under maintenance and not available for booking:
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Bus ID</th>
                                                    <th>Bus Type</th>
                                                    <th>Plate Number</th>
                                                    <th>Route</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($maintenance_buses as $bus_id => $bus): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($bus_id); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['bus_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['plate_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['route_name']); ?></td>
                                                    <td><span class="badge bg-danger">Under Maintenance</span></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">These buses will be back in service once maintenance is complete.</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Inactive Buses Information (if any) -->
                        <?php if (count($inactive_buses) > 0): ?>
                        <div class="mt-4">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-ban me-2"></i>Inactive Buses</h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-secondary" role="alert">
                                        <i class="fas fa-info-circle me-2"></i>
                                        The following buses are currently inactive and not available for booking:
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Bus ID</th>
                                                    <th>Bus Type</th>
                                                    <th>Plate Number</th>
                                                    <th>Route</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($inactive_buses as $bus_id => $bus): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($bus_id); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['bus_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['plate_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($bus['route_name']); ?></td>
                                                    <td><span class="badge bg-secondary">Inactive</span></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">These buses are temporarily inactive and not included in the current schedule.</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
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
    
    <!-- Trip filtering functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get all trip filter items
            const tripFilterItems = document.querySelectorAll('.trip-filter-item');
            const scheduleItems = document.querySelectorAll('.schedule-item');
            
            // Add click event to each filter item
            tripFilterItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Remove active class from all items
                    tripFilterItems.forEach(i => i.classList.remove('active'));
                    
                    // Add active class to clicked item
                    this.classList.add('active');
                    
                    // Get selected trip
                    const selectedTrip = this.getAttribute('data-trip');
                    
                    // Show/hide schedule items based on selection
                    scheduleItems.forEach(scheduleItem => {
                        if (selectedTrip === 'all') {
                            scheduleItem.style.display = 'block';
                        } else {
                            // Convert the trip name to a class name format (e.g., "1st Trip" to "trip-1st-trip")
                            const tripClass = 'trip-' + selectedTrip.toLowerCase().replace(' ', '-');
                            
                            // Check if the schedule item has the selected trip class
                            if (scheduleItem.classList.contains(tripClass)) {
                                scheduleItem.style.display = 'block';
                            } else {
                                scheduleItem.style.display = 'none';
                            }
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>