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
require_once "../../backend/connections/config.php"; 
require_once "../../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_origin = isset($_GET['origin']) ? $_GET['origin'] : '';
$selected_destination = isset($_GET['destination']) ? $_GET['destination'] : '';
$booking_success = false;
$booking_error = '';

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_ticket'])) {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $seat_number = isset($_POST['seat_number']) ? intval($_POST['seat_number']) : 0;
    $booking_date = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    
    // Validation
    $errors = [];
    if ($bus_id <= 0) {
        $errors[] = "Please select a valid bus";
    }
    if ($seat_number <= 0) {
        $errors[] = "Please select a seat";
    }
    if (empty($booking_date)) {
        $errors[] = "Please select a travel date";
    }
    
    if (empty($errors)) {
        try {
            // Check if seat is already booked
            $check_query = "SELECT id FROM bookings WHERE bus_id = ? AND seat_number = ? AND booking_date = ? AND booking_status = 'confirmed'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("iis", $bus_id, $seat_number, $booking_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $booking_error = "This seat is already booked. Please select another seat.";
            } else {
                // Insert booking
                $insert_query = "INSERT INTO bookings (bus_id, user_id, seat_number, booking_date, booking_status, created_at) 
                                VALUES (?, ?, ?, ?, 'confirmed', NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("iiis", $bus_id, $user_id, $seat_number, $booking_date);
                
                if ($insert_stmt->execute()) {
                    $booking_success = true;
                    $booking_id = $conn->insert_id;
                    
                    // Generate booking reference
                    $booking_reference = 'BK-' . date('Ymd') . '-' . $booking_id;
                    
                    // Update booking with reference
                    $update_query = "UPDATE bookings SET booking_reference = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $booking_reference, $booking_id);
                    $update_stmt->execute();
                    
                } else {
                    $booking_error = "Error creating booking. Please try again.";
                }
            }
        } catch (Exception $e) {
            $booking_error = "Database error: " . $e->getMessage();
        }
    } else {
        $booking_error = implode(", ", $errors);
    }
}

// Fetch available locations (for origin and destination dropdowns)
$locations = [];
try {
    $locations_query = "SELECT DISTINCT origin FROM buses UNION SELECT DISTINCT destination FROM buses ORDER BY origin";
    $locations_result = $conn->query($locations_query);
    
    if ($locations_result) {
        while ($row = $locations_result->fetch_assoc()) {
            $locations[] = $row['origin'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching locations: " . $e->getMessage());
}

// Fetch all buses for display (regardless of route selection)
$all_buses = [];
try {
    $all_buses_query = "SELECT b.*, 
                        (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND booking_status = 'confirmed') as active_bookings
                        FROM buses b 
                        ORDER BY b.status, b.id";
    
    $all_buses_result = $conn->query($all_buses_query);
    
    if ($all_buses_result) {
        while ($row = $all_buses_result->fetch_assoc()) {
            $all_buses[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching all buses: " . $e->getMessage());
}

// Fetch available buses based on selected criteria
$available_buses = [];
if (!empty($selected_origin) && !empty($selected_destination)) {
    try {
        $buses_query = "SELECT DISTINCT  b.id, b.bus_type, b.seat_capacity, b.plate_number, b.origin, b.destination, 
                        b.driver_name, b.conductor_name, b.status, 
                        TIME_FORMAT(s.departure_time, '%h:%i %p') as departure_time,
                        TIME_FORMAT(s.arrival_time, '%h:%i %p') as arrival_time,
                        s.fare_amount
                        FROM buses b
                        JOIN schedules s ON b.id = s.bus_id
                        WHERE b.origin = ? AND b.destination = ? AND b.status = 'Active' 
                        ORDER BY s.departure_time";
        
        $buses_stmt = $conn->prepare($buses_query);
        $buses_stmt->bind_param("ss", $selected_origin, $selected_destination);
        $buses_stmt->execute();
        $buses_result = $buses_stmt->get_result();
        
        if ($buses_result) {
            while ($row = $buses_result->fetch_assoc()) {
                $available_buses[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching buses: " . $e->getMessage());
    }
}

// Fetch user data (optional, for the form)
$user_data = null;
try {
    $user_query = "SELECT * FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result && $user_result->num_rows === 1) {
        $user_data = $user_result->fetch_assoc();
    }
} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Ticket - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="css/user.css">
    <style>
        /* Seat map styles */
        .seat {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: bold;
            color: white;
            transition: all 0.2s;
        }
        
        .seat.available {
            background-color: #28a745;
        }
        
        .seat.booked {
            background-color: #dc3545;
            cursor: not-allowed;
        }
        
        .seat.selected {
            background-color: #007bff;
            transform: scale(1.1);
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
        }
        
        .seat-row {
            display: flex;
            justify-content: center;
            margin-bottom: 8px;
            gap: 8px;
        }
        
        .aisle {
            width: 20px;
        }
        
        .driver-area {
            max-width: 100px;
            margin: 0 auto;
        }
        
        .bus-selector {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .bus-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .bus-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .bus-card.selected {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.3);
        }
        
        .ticket-summary-card {
            position: sticky;
            top: 20px;
        }
        
        .booking-steps .step {
            padding: 10px;
            border-bottom: 2px solid #eee;
            margin-bottom: 15px;
        }
        
        .booking-steps .step.active {
            border-bottom-color: #007bff;
        }
        
        .booking-steps .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #eee;
            color: #666;
            margin-right: 10px;
        }
        
        .booking-steps .step.active .step-number {
            background-color: #007bff;
            color: white;
        }
        
        /* Fleet display styles */
        .fleet-section {
            margin-top: 30px;
            margin-bottom: 30px;
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
        
        .bus-info-table {
            font-size: 0.9rem;
        }
        
        .bus-info-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: #007bff;
            border-color: #dee2e6 #dee2e6 #fff;
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
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs/routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="booking.php">Book Ticket</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
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
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-ticket-alt me-2"></i>Book Your Ticket</h2>
                
                <?php if ($booking_success): ?>
                <!-- Booking Success Message -->
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Booking Successful!</h4>
                    <p>Your ticket has been booked successfully. Your booking reference is: <strong><?php echo $booking_reference; ?></strong></p>
                    <hr>
                    <p class="mb-0">You can view your booking details in <a href="my_bookings.php" class="alert-link">My Bookings</a> page.</p>
                </div>
                <?php elseif (!empty($booking_error)): ?>
                <!-- Booking Error Message -->
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $booking_error; ?>
                </div>
                <?php endif; ?>
                
                <!-- Booking Options Tabs -->
                <ul class="nav nav-tabs mb-4" id="bookingTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="book-ticket-tab" data-bs-toggle="tab" data-bs-target="#book-ticket" type="button" role="tab" aria-controls="book-ticket" aria-selected="true">
                            <i class="fas fa-ticket-alt me-2"></i>Book a Ticket
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="view-fleet-tab" data-bs-toggle="tab" data-bs-target="#view-fleet" type="button" role="tab" aria-controls="view-fleet" aria-selected="false">
                            <i class="fas fa-bus-alt me-2"></i>View Bus Fleet
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="bookingTabsContent">
                    <!-- Book Ticket Tab Content -->
                    <div class="tab-pane fade show active" id="book-ticket" role="tabpanel" aria-labelledby="book-ticket-tab">
                        <!-- Booking Steps -->
                        <div class="card mb-4">
                            <div class="card-body booking-steps">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="step active" id="step1">
                                            <span class="step-number">1</span>
                                            <span class="step-text">Select Route & Date</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="step" id="step2">
                                            <span class="step-number">2</span>
                                            <span class="step-text">Choose Bus & Schedule</span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="step" id="step3">
                                            <span class="step-number">3</span>
                                            <span class="step-text">Select Seat & Confirm</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <!-- Route Selection (Step 1) -->
                                <div class="card mb-4" id="route-selection">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-route me-2"></i>Select Your Route</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="booking.php" method="GET" id="routeForm">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="origin" class="form-label">Origin (From)*</label>
                                                        <select class="form-select" id="origin" name="origin" required>
                                                            <option value="" disabled <?php echo empty($selected_origin) ? 'selected' : ''; ?>>Select Origin</option>
                                                            <?php foreach ($locations as $location): ?>
                                                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $selected_origin === $location ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($location); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="destination" class="form-label">Destination (To)*</label>
                                                        <select class="form-select" id="destination" name="destination" required>
                                                            <option value="" disabled <?php echo empty($selected_destination) ? 'selected' : ''; ?>>Select Destination</option>
                                                            <?php foreach ($locations as $location): ?>
                                                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $selected_destination === $location ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($location); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="date" class="form-label">Travel Date*</label>
                                                        <input type="date" class="form-control" id="date" name="date" 
                                                               value="<?php echo $selected_date; ?>" 
                                                               min="<?php echo date('Y-m-d'); ?>" 
                                                               max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" 
                                                               required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-center">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-search me-2"></i>Search Buses
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <?php if (!empty($selected_origin) && !empty($selected_destination)): ?>
                                <!-- Bus Selection (Step 2) -->
                                <div class="card mb-4" id="bus-selection">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-bus me-2"></i>Available Buses</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($available_buses) > 0): ?>
                                        <div class="bus-list">
                                            <?php foreach ($available_buses as $index => $bus): ?>
                                            <div class="bus-card p-3" data-bus-id="<?php echo $bus['id']; ?>" data-fare="<?php echo $bus['fare_amount']; ?>">
                                                <div class="row align-items-center">
                                                    <div class="col-md-1 text-center">
                                                        <div class="bus-icon">
                                                            <i class="fas fa-bus fs-3 text-primary"></i>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <h5 class="mb-1"><?php echo htmlspecialchars($bus['origin']); ?> to <?php echo htmlspecialchars($bus['destination']); ?></h5>
                                                        <p class="mb-0 text-muted">
                                                            <small>
                                                                <i class="fas fa-clock me-1"></i><?php echo $bus['departure_time']; ?> - <?php echo $bus['arrival_time']; ?>
                                                            </small>
                                                        </p>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <p class="mb-0">
                                                            <span class="badge <?php echo $bus['bus_type'] === 'Aircondition' ? 'bg-info text-dark' : 'bg-secondary'; ?> me-2">
                                                                <?php echo $bus['bus_type'] === 'Aircondition' ? '<i class="fas fa-snowflake me-1"></i> Aircon' : '<i class="fas fa-bus me-1"></i> Regular'; ?>
                                                            </span>
                                                        </p>
                                                        <p class="mb-0 text-muted">
                                                            <small>
                                                                <i class="fas fa-chair me-1"></i><?php echo $bus['seat_capacity']; ?> Seats
                                                            </small>
                                                        </p>
                                                    </div>
                                                    <div class="col-md-2 text-center">
                                                        <h5 class="mb-0 text-primary">₱<?php echo number_format($bus['fare_amount'], 2); ?></h5>
                                                        <small class="text-muted">per person</small>
                                                    </div>
                                                    <div class="col-md-2 text-end">
                                                        <button type="button" class="btn btn-outline-primary btn-sm select-bus">
                                                            <i class="fas fa-check me-1"></i>Select
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="alert alert-info" role="alert">
                                            <i class="fas fa-info-circle me-2"></i>No buses available for the selected route and date. Please try a different route or date.
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Seat Selection (Step 3) -->
                                <div class="card mb-4" id="seat-selection" style="display: none;">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-chair me-2"></i>Select Your Seat</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-12 text-center">
                                                <div class="seat-legend d-flex justify-content-center gap-4 mb-3">
                                                    <div><span class="seat available d-inline-block me-2" style="width: 25px; height: 25px;"></span> Available</div>
                                                    <div><span class="seat booked d-inline-block me-2" style="width: 25px; height: 25px;"></span> Booked</div>
                                                    <div><span class="seat selected d-inline-block me-2" style="width: 25px; height: 25px;"></span> Your Selection</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mb-3">
                                            <div class="driver-area mb-4">
                                                <div class="p-2 bg-secondary text-white rounded d-inline-block">
                                                    <i class="fas fa-steering-wheel"></i> Driver
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php
                                        // Calculate booked seats for the current bus and date
                                        $booked_seats_query = "SELECT COUNT(*) as booked_count 
                                                                FROM bookings 
                                                                WHERE bus_id = ? 
                                                                AND booking_date = ? 
                                                                AND booking_status = 'confirmed'";
                                        $booked_seats_stmt = $conn->prepare($booked_seats_query);
                                        
                                        // Use the first available bus's ID if exists
                                        $bus_id = !empty($available_buses) ? $available_buses[0]['id'] : 0;
                                        $booked_seats_stmt->bind_param("is", $bus_id, $selected_date);
                                        $booked_seats_stmt->execute();
                                        $booked_seats_result = $booked_seats_stmt->get_result();
                                        $booked_seats_count = 0;
                                        
                                        if ($booked_seats_result && $booked_seats_result->num_rows > 0) {
                                            $booked_seats_row = $booked_seats_result->fetch_assoc();
                                            $booked_seats_count = $booked_seats_row['booked_count'];
                                        }
                                        
                                        // Get total seat capacity for the bus
                                        $total_seats = !empty($available_buses) ? $available_buses[0]['seat_capacity'] : 0;
                                        ?>
                                        
                                        <div id="seatMapContainer" class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                                            <!-- Seat map will be dynamically loaded here -->
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading seats...</span>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-2 mb-3">
                                            <div class="small text-muted">
                                                <span id="bookedSeatCount"><?php echo $booked_seats_count; ?></span> seats booked out of <span id="totalSeatCount"><?php echo $total_seats; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>Click on an available seat to select it. Your selected seat will be highlighted in blue.
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-4">
                                <!-- Booking Summary -->
                                <div class="card ticket-summary-card">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Booking Summary</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="booking.php" method="POST" id="bookingForm">
                                            <input type="hidden" name="bus_id" id="summary_bus_id" value="">
                                            <input type="hidden" name="seat_number" id="summary_seat_number" value="">
                                            <input type="hidden" name="booking_date" id="summary_booking_date" value="<?php echo $selected_date; ?>">
                                            <input type="hidden" name="book_ticket" value="1">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Passenger</label>
                                                <div class="form-control bg-light"><?php echo htmlspecialchars($user_name); ?></div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Route</label>
                                                <div class="form-control bg-light" id="summary_route">
                                                    <?php if (!empty($selected_origin) && !empty($selected_destination)): ?>
                                                    <?php echo htmlspecialchars($selected_origin); ?> to <?php echo htmlspecialchars($selected_destination); ?>
                                                    <?php else: ?>
                                                    Not selected
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Travel Date</label>
                                                <div class="form-control bg-light" id="summary_date">
                                                    <?php echo date('F d, Y', strtotime($selected_date)); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Bus Type</label>
                                                <div class="form-control bg-light" id="summary_bus_type">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Departure Time</label>
                                                <div class="form-control bg-light" id="summary_departure">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Selected Seat</label>
                                                <div class="form-control bg-light" id="summary_seat">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Fare Amount</label>
                                                <div class="form-control bg-light" id="summary_fare">₱0.00</div>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-success" id="confirmBookingBtn" disabled>
                                                    <i class="fas fa-ticket-alt me-2"></i>Confirm Booking
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- View Bus Fleet Tab Content -->
                    <div class="tab-pane fade" id="view-fleet" role="tabpanel" aria-labelledby="view-fleet-tab">
                        <div class="fleet-section">
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>Viewing all buses in our fleet. Click on a row to see detailed information about the bus.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <!-- Buses Table -->
                                    <div class="card">
                                        <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0"><i class="fas fa-bus-alt me-2"></i>Registered Buses</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if (count($all_buses) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover bus-info-table">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Type</th>
                                                            <th>Plate Number</th>
                                                            <th>Origin</th>
                                                            <th>Destination</th>
                                                            <th>Capacity</th>
                                                            <th>Driver</th>
                                                            <th>Conductor</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($all_buses as $bus): ?>
                                                        <tr class="bus-row" data-bs-toggle="collapse" data-bs-target="#busDetails<?php echo $bus['id']; ?>" aria-expanded="false" aria-controls="busDetails<?php echo $bus['id']; ?>">
                                                            <td><?php echo $bus['id']; ?></td>
                                                            <td>
                                                                <span class="badge <?php echo $bus['bus_type'] === 'Aircondition' ? 'bg-info text-dark' : 'bg-secondary'; ?>">
                                                                    <?php echo $bus['bus_type'] === 'Aircondition' ? '<i class="fas fa-snowflake me-1"></i> Aircon' : '<i class="fas fa-fan me-1"></i> Regular'; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($bus['plate_number']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['origin']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['destination']); ?></td>
                                                            <td><?php echo $bus['seat_capacity']; ?> seats</td>
                                                            <td><?php echo htmlspecialchars($bus['driver_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['conductor_name']); ?></td>
                                                            <td>
                                                                <span class="status-indicator <?php echo $bus['status'] === 'Active' ? 'status-active' : 'status-maintenance'; ?>"></span>
                                                                <?php echo $bus['status']; ?>
                                                            </td>
                                                            <td>
                                                                <a href="booking.php?origin=<?php echo urlencode($bus['origin']); ?>&destination=<?php echo urlencode($bus['destination']); ?>" class="btn btn-sm btn-primary">
                                                                    <i class="fas fa-ticket-alt me-1"></i>Book
                                                                </a>
                                                            </td>
                                                        </tr>
                                                        <tr class="collapse" id="busDetails<?php echo $bus['id']; ?>">
                                                            <td colspan="10">
                                                                <div class="card card-body bg-light mb-0">
                                                                    <div class="row">
                                                                        <div class="col-md-6">
                                                                            <h6><i class="fas fa-info-circle me-2"></i>Bus Details</h6>
                                                                            <ul class="list-unstyled">
                                                                                <li><strong>Bus ID:</strong> #<?php echo $bus['id']; ?></li>
                                                                                <li><strong>Type:</strong> <?php echo $bus['bus_type']; ?></li>
                                                                                <li><strong>Plate Number:</strong> <?php echo htmlspecialchars($bus['plate_number']); ?></li>
                                                                                <li><strong>Seating Capacity:</strong> <?php echo $bus['seat_capacity']; ?> seats</li>
                                                                                <li><strong>Current Status:</strong> <?php echo $bus['status']; ?></li>
                                                                            </ul>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <h6><i class="fas fa-route me-2"></i>Route & Personnel</h6>
                                                                            <ul class="list-unstyled">
                                                                                <li><strong>Route:</strong> <?php echo htmlspecialchars($bus['origin']); ?> to <?php echo htmlspecialchars($bus['destination']); ?></li>
                                                                                <li><strong>Driver:</strong> <?php echo htmlspecialchars($bus['driver_name']); ?></li>
                                                                                <li><strong>Conductor:</strong> <?php echo htmlspecialchars($bus['conductor_name']); ?></li>
                                                                                <li><strong>Active Bookings:</strong> <?php echo $bus['active_bookings']; ?></li>
                                                                                <li><strong>Added On:</strong> <?php echo date('M d, Y', strtotime($bus['created_at'])); ?></li>
                                                                            </ul>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>No buses have been registered in the system yet.
                                            </div>
                                            <?php endif; ?>
                                        </div>
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
                        <li><a href="tabs/routes.php" class="text-white">Routes</a></li>
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
        // Initialize variables
        let selectedBusId = null;
        let selectedSeatNumber = null;
        let selectedBusData = null;
        
        // Bus selection
        document.querySelectorAll('.bus-card').forEach(function(card) {
            card.addEventListener('click', function() {
                const busId = this.getAttribute('data-bus-id');
                const fareAmount = this.getAttribute('data-fare');
                
                // Remove selection from all buses
                document.querySelectorAll('.bus-card').forEach(function(c) {
                    c.classList.remove('selected');
                });
                
                // Select this bus
                this.classList.add('selected');
                selectedBusId = busId;
                
                // Store bus data for summary
                selectedBusData = {
                    id: busId,
                    type: this.querySelector('.badge').textContent.trim(),
                    departure: this.querySelector('.text-muted small').textContent.trim(),
                    fare: fareAmount
                };
                
                // Update summary
                document.getElementById('summary_bus_id').value = busId;
                document.getElementById('summary_bus_type').textContent = selectedBusData.type;
                document.getElementById('summary_departure').textContent = selectedBusData.departure;
                document.getElementById('summary_fare').textContent = '₱' + parseFloat(fareAmount).toFixed(2);
                
                // Show seat selection
                document.getElementById('seat-selection').style.display = 'block';
                
                // Update steps
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step2').classList.add('active');
                
                // Generate seat map
                generateSeatMap(busId, parseInt(this.querySelector('.text-muted small').textContent.match(/(\d+) Seats/)[1]));
                
                // Scroll to seat selection
                document.getElementById('seat-selection').scrollIntoView({ behavior: 'smooth' });
            });
        });
        
        // Function to fetch booked seats from the database
        function fetchBookedSeats(busId) {
            return new Promise((resolve, reject) => {
                // Make an AJAX call to the server to get booked seats for this bus
                fetch(`../tabs/auth/get_booked_seats.php?bus_id=${busId}&date=${document.getElementById('date').value}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        // Return the array of booked seat numbers
                        resolve(data.bookedSeats);
                    })
                    .catch(error => {
                        console.error('Error fetching booked seats:', error);
                        // If there's an error, assume no seats are booked
                        resolve([]);
                    });
            });
        }
        
        function generateSeatMap(busId, totalSeats) {
            const seatMapContainer = document.getElementById('seatMapContainer');
            seatMapContainer.innerHTML = '';
            
            // Get booked seats from database
            fetchBookedSeats(busId).then(bookedSeats => {
                let bookedCount = 0;
                
                // Set seating layout based on bus type and seat count
                let seatsPerRow = 4; // Default 2-2 layout
                let rowCount = Math.ceil(totalSeats / seatsPerRow);
                
                // Create bus layout - with an aisle in the middle
                let seatNumber = 1;
                
                for (let row = 1; row <= rowCount; row++) {
                    const rowDiv = document.createElement('div');
                    rowDiv.className = 'seat-row';
                    
                    // Add left side seats (2 seats)
                    for (let i = 0; i < seatsPerRow/2; i++) {
                        if (seatNumber <= totalSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            
                            if (isBooked) bookedCount++;
                            seatNumber++;
                        }
                    }
                    
                    // Add aisle
                    const aisleDiv = document.createElement('div');
                    aisleDiv.className = 'aisle';
                    rowDiv.appendChild(aisleDiv);
                    
                    // Add right side seats (2 seats)
                    for (let i = 0; i < seatsPerRow/2; i++) {
                        if (seatNumber <= totalSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            
                            if (isBooked) bookedCount++;
                            seatNumber++;
                        }
                    }
                    
                    seatMapContainer.appendChild(rowDiv);
                }
                
                // Update counters
                document.getElementById('bookedSeatCount').textContent = bookedCount;
                document.getElementById('totalSeatCount').textContent = totalSeats;
            });
        }
        
        function createSeatElement(seatNumber, isBooked) {
            const seat = document.createElement('div');
            seat.className = `seat ${isBooked ? 'booked' : 'available'}`;
            seat.dataset.seatNumber = seatNumber;
            seat.textContent = seatNumber;
            
            // Add tooltip
            seat.title = `Seat ${seatNumber}: ${isBooked ? 'Booked' : 'Available'}`;
            
            // Add click handler for available seats
            if (!isBooked) {
                seat.addEventListener('click', function() {
                    // Only allow selection if a bus is selected
                    if (!selectedBusId) {
                        alert('Please select a bus first');
                        return;
                    }
                    
                    // Remove selection from all seats
                    document.querySelectorAll('.seat.selected').forEach(function(s) {
                        s.classList.remove('selected');
                        s.classList.add('available');
                    });
                    
                    // Select this seat
                    this.classList.remove('available');
                    this.classList.add('selected');
                    selectedSeatNumber = this.dataset.seatNumber;
                    
                    // Update summary
                    document.getElementById('summary_seat_number').value = selectedSeatNumber;
                    document.getElementById('summary_seat').textContent = 'Seat ' + selectedSeatNumber;
                    
                    // Enable confirm button
                    document.getElementById('confirmBookingBtn').disabled = false;
                    
                    // Update steps
                    document.getElementById('step2').classList.remove('active');
                    document.getElementById('step3').classList.add('active');
                });
            }
            
            return seat;
        }
        
        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            // Check if seat is selected
            if (!selectedSeatNumber) {
                e.preventDefault();
                alert('Please select a seat');
            }
        });
        
        // Prevent selecting same origin and destination
        document.getElementById('origin').addEventListener('change', function() {
            const destination = document.getElementById('destination');
            const selectedValue = this.value;
            
            // Enable all options
            for (let i = 0; i < destination.options.length; i++) {
                destination.options[i].disabled = false;
            }
            
            // Disable matching option in destination
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
        });
        
        // Make bus rows clickable for details
        document.querySelectorAll('.bus-row').forEach(function(row) {
            row.style.cursor = 'pointer';
        });
        
        // Preserve active tab on page refresh
        document.addEventListener('DOMContentLoaded', function() {
            // Get the active tab from URL if present
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab === 'fleet') {
                // Activate fleet tab
                document.getElementById('view-fleet-tab').click();
            }
            
            // Add tab parameter to form submission
            document.getElementById('routeForm').addEventListener('submit', function() {
                const activeTab = document.querySelector('.nav-link.active').getAttribute('id');
                if (activeTab === 'view-fleet-tab') {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'tab';
                    hiddenInput.value = 'fleet';
                    this.appendChild(hiddenInput);
                }
            });
        });
        
        // Initialize: Trigger origin change to set initial disabled states
        const originSelect = document.getElementById('origin');
        if (originSelect.value) {
            const event = new Event('change');
            originSelect.dispatchEvent(event);
        }
    </script>
</body>
</html>