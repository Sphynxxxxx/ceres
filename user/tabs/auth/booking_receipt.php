<?php
/**
 * Enhanced Booking Receipt Page for Current Booking System
 * Compatible with new database schema and booking functionality
 * Place this file in: auth/booking_receipt.php
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

// Database connection
require_once "../../../backend/connections/config.php";

// Get booking information from URL parameters - FIXED VERSION
$booking_ids = isset($_GET['booking_ids']) ? $_GET['booking_ids'] : '';
$booking_refs = isset($_GET['booking_refs']) ? $_GET['booking_refs'] : '';
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$group_booking_id = isset($_GET['group_booking_id']) ? $_GET['group_booking_id'] : '';
$group_reference = isset($_GET['group_reference']) ? $_GET['group_reference'] : '';

// Normalize group parameters - use whichever is provided
if (!empty($group_reference) && empty($group_booking_id)) {
    $group_booking_id = $group_reference;
} elseif (!empty($group_booking_id) && empty($group_reference)) {
    $group_reference = $group_booking_id;
}

$bookings = [];
$total_fare = 0;
$total_savings = 0;
$bus_info = null;
$route_info = null;
$user_info = null;

try {
    $query = "";
    $stmt_params = [];
    $stmt_types = "";
    
    if (!empty($booking_ids)) {
        // Multiple bookings by IDs
        $ids = array_map('intval', explode(',', $booking_ids));
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $query = "SELECT b.*, u.first_name, u.last_name, u.email, u.contact_number,
                         bus.bus_type, bus.plate_number, bus.route_name, bus.driver_name, bus.conductor_name,
                         s.departure_time, s.arrival_time, s.trip_number,
                         r.fare as route_base_fare, r.origin, r.destination, r.distance, r.estimated_duration
                  FROM bookings b
                  LEFT JOIN users u ON b.user_id = u.id
                  LEFT JOIN buses bus ON b.bus_id = bus.id
                  LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                  LEFT JOIN routes r ON bus.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                  WHERE b.id IN ($placeholders)
                  ORDER BY b.seat_number";
        
        $stmt_params = $ids;
        $stmt_types = str_repeat('i', count($ids));
        
    } else if (!empty($booking_refs)) {
        // Multiple bookings by references
        $refs = explode(',', $booking_refs);
        $placeholders = str_repeat('?,', count($refs) - 1) . '?';
        
        $query = "SELECT b.*, u.first_name, u.last_name, u.email, u.contact_number,
                         bus.bus_type, bus.plate_number, bus.route_name, bus.driver_name, bus.conductor_name,
                         s.departure_time, s.arrival_time, s.trip_number,
                         r.fare as route_base_fare, r.origin, r.destination, r.distance, r.estimated_duration
                  FROM bookings b
                  LEFT JOIN users u ON b.user_id = u.id
                  LEFT JOIN buses bus ON b.bus_id = bus.id
                  LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                  LEFT JOIN routes r ON bus.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                  WHERE b.booking_reference IN ($placeholders)
                  ORDER BY b.seat_number";
        
        $stmt_params = $refs;
        $stmt_types = str_repeat('s', count($refs));
        
    } else if ($booking_id > 0) {
        // Single booking by ID
        $query = "SELECT b.*, u.first_name, u.last_name, u.email, u.contact_number,
                         bus.bus_type, bus.plate_number, bus.route_name, bus.driver_name, bus.conductor_name,
                         s.departure_time, s.arrival_time, s.trip_number,
                         r.fare as route_base_fare, r.origin, r.destination, r.distance, r.estimated_duration
                  FROM bookings b
                  LEFT JOIN users u ON b.user_id = u.id
                  LEFT JOIN buses bus ON b.bus_id = bus.id
                  LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                  LEFT JOIN routes r ON bus.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                  WHERE b.id = ?";
        
        $stmt_params = [$booking_id];
        $stmt_types = "i";
        
    } else if (!empty($group_booking_id)) {
        // Group booking by group ID - FIXED QUERY
        $query = "SELECT b.*, u.first_name, u.last_name, u.email, u.contact_number,
                         bus.bus_type, bus.plate_number, bus.route_name, bus.driver_name, bus.conductor_name,
                         s.departure_time, s.arrival_time, s.trip_number,
                         r.fare as route_base_fare, r.origin, r.destination, r.distance, r.estimated_duration
                  FROM bookings b
                  LEFT JOIN users u ON b.user_id = u.id
                  LEFT JOIN buses bus ON b.bus_id = bus.id
                  LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                  LEFT JOIN routes r ON bus.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                  WHERE b.group_booking_id = ?
                  ORDER BY b.seat_number";
        
        $stmt_params = [$group_booking_id];
        $stmt_types = "s";
    } else {
        throw new Exception("No booking information provided");
    }
    
    // Debug: Log the query and parameters
    error_log("Receipt Query: " . $query);
    error_log("Parameters: " . print_r($stmt_params, true));
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    if (!empty($stmt_params)) {
        $stmt->bind_param($stmt_types, ...$stmt_params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("No bookings found with the provided information. Group ID: " . $group_booking_id);
    }
    
    while ($row = $result->fetch_assoc()) {
        // FIXED: Use the specific passenger's data for fare calculation
        $base_fare = floatval($row['base_fare'] ?: $row['route_base_fare']);
        $discount_amount = floatval($row['discount_amount']);
        $final_fare = floatval($row['final_fare']);
        $passenger_discount_type = $row['discount_type'];
        
        // Fallback calculation if database values are missing - use THIS passenger's discount type
        if (empty($row['final_fare']) || empty($row['discount_amount'])) {
            if ($passenger_discount_type !== 'regular' && in_array($passenger_discount_type, ['student', 'senior', 'pwd'])) {
                $discount_amount = $base_fare * 0.2; // 20% discount
                $final_fare = $base_fare * 0.8;
            } else {
                $discount_amount = 0;
                $final_fare = $base_fare;
            }
        }
        
        // Update the row data with calculated values
        $row['base_fare'] = $base_fare;
        $row['discount_amount'] = $discount_amount;
        $row['final_fare'] = $final_fare;
        
        $total_fare += $final_fare;
        $total_savings += $discount_amount;
        
        $bookings[] = $row;
        
        // Debug logging for each passenger's fare
        error_log("Receipt - Passenger: {$row['passenger_name']}, Discount Type: {$passenger_discount_type}, Base: {$base_fare}, Discount: {$discount_amount}, Final: {$final_fare}");
        
        // Store bus and route info (same for all bookings in a group)
        if (!$bus_info) {
            $bus_info = [
                'bus_type' => $row['bus_type'],
                'plate_number' => $row['plate_number'],
                'route_name' => $row['route_name'],
                'driver_name' => $row['driver_name'],
                'conductor_name' => $row['conductor_name'],
                'departure_time' => $row['departure_time'],
                'arrival_time' => $row['arrival_time'],
                'trip_number' => $row['trip_number']
            ];
            
            $route_info = [
                'origin' => $row['origin'],
                'destination' => $row['destination'],
                'distance' => $row['distance'],
                'estimated_duration' => $row['estimated_duration']
            ];
            
            $user_info = [
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'contact_number' => $row['contact_number']
            ];
        }
    }
    
    if (empty($bookings)) {
        throw new Exception("No bookings found with the provided information");
    }
    
    // Log successful receipt generation
    error_log("Booking receipt generated for " . count($bookings) . " bookings, total fare: " . $total_fare);
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Booking receipt error: " . $e->getMessage());
}

// Helper function to get payment method display
function getPaymentMethodDisplay($method) {
    $methods = [
        'counter' => ['icon' => 'money-bill-wave', 'text' => 'Pay Over the Counter'],
        'gcash' => ['icon' => 'mobile-alt', 'text' => 'GCash'],
        'paymaya' => ['icon' => 'credit-card', 'text' => 'PayMaya']
    ];
    return $methods[$method] ?? ['icon' => 'credit-card', 'text' => ucfirst($method)];
}

// Helper function to get status class
function getStatusClass($status) {
    $classes = [
        'confirmed' => 'status-confirmed',
        'pending' => 'status-pending',
        'cancelled' => 'status-cancelled',
        'completed' => 'status-completed'
    ];
    return $classes[$status] ?? 'status-pending';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/navfot.css">
    <style>
        .receipt-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .receipt-header::before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            clip-path: polygon(0 0, 100% 0, 95% 100%, 5% 100%);
        }
        
        .receipt-body {
            padding: 30px;
        }
        
        .ticket-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            transition: all 0.3s;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .ticket-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 15px 20px;
            border-bottom: 2px dashed #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ticket-number {
            font-weight: bold;
            color: #007bff;
            font-size: 1.1rem;
        }
        
        .seat-number {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,123,255,0.3);
        }
        
        .ticket-body {
            padding: 25px;
        }
        
        .fare-breakdown {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .discount-badge {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(40,167,69,0.3);
        }
        
        .status-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-confirmed {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-cancelled {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .total-section {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
        }
        
        .group-booking-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .qr-code-section {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            margin-top: 25px;
        }
        
        .important-notes {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 20px;
            margin-top: 25px;
        }
        
        .verification-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .verification-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
        }
        
        .verified {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .pending-verification {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: white;
        }
        
        .not-required {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }
        
        .customer-info {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .savings-highlight {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            margin-top: 15px;
        }
        
        .print-section {
            text-align: center;
            margin: 30px 0;
        }
        
        .info-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .receipt-container {
                box-shadow: none;
                max-width: none;
            }
            
            body {
                background: white !important;
            }
            
            .ticket-card {
                break-inside: avoid;
            }
        }
        
        @media (max-width: 768px) {
            .receipt-body {
                padding: 20px;
            }
            
            .ticket-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .seat-number {
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../mybookings.php">
                    <i class="fas fa-list me-1"></i>My Bookings
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        </div>
        <div class="text-center">
            <a href="../booking.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Booking
            </a>
        </div>
        <?php else: ?>
        
        <div class="receipt-container">
            <!-- Receipt Header -->
            <div class="receipt-header">
                <div class="row align-items-center">
                    <div class="col-md-3 text-start">
                        <i class="fas fa-bus-alt fa-3x"></i>
                    </div>
                    <div class="col-md-6">
                        <h2 class="mb-1">
                            <i class="fas fa-receipt me-2"></i>Booking Receipt
                        </h2>
                        <p class="mb-0">ISAT-U Ceres Bus Ticket System</p>
                        <?php if (!empty($bookings[0]['group_booking_id']) && count($bookings) > 1): ?>
                        <small class="badge bg-light text-dark mt-2">
                            Group Booking: <?php echo htmlspecialchars($bookings[0]['group_booking_id']); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-end">
                        <div class="text-end">
                            <small>Receipt Date</small>
                            <div class="fw-bold"><?php echo date('M d, Y'); ?></div>
                            <div class="fw-bold"><?php echo date('h:i A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Receipt Body -->
            <div class="receipt-body">
                
                <!-- Customer Information -->
                <?php if ($user_info): ?>
                <div class="customer-info">
                    <h5 class="text-primary mb-3">
                        <i class="fas fa-user me-2"></i>Customer Information
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Name:</strong> <?php echo htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Email:</strong> <?php echo htmlspecialchars($user_info['email']); ?>
                        </div>
                        <?php if ($user_info['contact_number']): ?>
                        <div class="col-md-6 mt-2">
                            <strong>Contact:</strong> <?php echo htmlspecialchars($user_info['contact_number']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Trip Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5 class="text-primary mb-3">
                                <i class="fas fa-route me-2"></i>Trip Information
                            </h5>
                            <div class="row mb-2">
                                <div class="col-5"><strong>From:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($route_info['origin']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>To:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($route_info['destination']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Date:</strong></div>
                                <div class="col-7"><?php echo date('F d, Y', strtotime($bookings[0]['booking_date'])); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Departure:</strong></div>
                                <div class="col-7"><?php echo date('h:i A', strtotime($bus_info['departure_time'])); ?></div>
                            </div>
                            <?php if ($bus_info['arrival_time']): ?>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Arrival:</strong></div>
                                <div class="col-7"><?php echo date('h:i A', strtotime($bus_info['arrival_time'])); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($route_info['distance']): ?>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Distance:</strong></div>
                                <div class="col-7"><?php echo $route_info['distance']; ?> km</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5 class="text-primary mb-3">
                                <i class="fas fa-bus me-2"></i>Bus Information
                            </h5>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Bus Type:</strong></div>
                                <div class="col-7">
                                    <?php echo $bus_info['bus_type'] === 'Aircondition' ? 
                                        '<i class="fas fa-snowflake me-1"></i>Air Conditioned' : 
                                        '<i class="fas fa-fan me-1"></i>Regular'; ?>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Plate #:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($bus_info['plate_number']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Trip:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($bus_info['trip_number']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Driver:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($bus_info['driver_name']); ?></div>
                            </div>
                            <?php if ($bus_info['conductor_name']): ?>
                            <div class="row mb-2">
                                <div class="col-5"><strong>Conductor:</strong></div>
                                <div class="col-7"><?php echo htmlspecialchars($bus_info['conductor_name']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Group Booking Information -->
                <?php if (!empty($bookings[0]['group_booking_id']) && count($bookings) > 1): ?>
                <div class="group-booking-info">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-1">
                                <i class="fas fa-users me-2"></i>Group Booking Information
                            </h5>
                            <p class="mb-0">
                                Group ID: <?php echo htmlspecialchars($bookings[0]['group_booking_id']); ?> | 
                                Status: Confirmed
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="fw-bold">
                                <?php echo count($bookings); ?> Tickets
                            </div>
                            <small>Total Group Amount: ₱<?php echo number_format($total_fare, 2); ?></small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tickets -->
                <h5 class="text-primary mb-3">
                    <i class="fas fa-tickets me-2"></i>Ticket Details 
                    <span class="badge bg-primary"><?php echo count($bookings); ?> ticket<?php echo count($bookings) > 1 ? 's' : ''; ?></span>
                </h5>
                
                <?php foreach ($bookings as $index => $booking): ?>
                <div class="ticket-card">
                    <div class="ticket-header">
                        <div class="ticket-number">
                            <i class="fas fa-ticket-alt me-2"></i>
                            Ticket #<?php echo $index + 1; ?> - <?php echo htmlspecialchars($booking['booking_reference']); ?>
                        </div>
                        <div class="seat-number">
                            <i class="fas fa-chair me-1"></i>Seat <?php echo $booking['seat_number']; ?>
                        </div>
                    </div>
                    <div class="ticket-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong><i class="fas fa-user me-1"></i>Passenger Name:</strong><br>
                                <span><?php echo htmlspecialchars($booking['passenger_name'] ?: 'Not provided'); ?></span>
                            </div>
                            <div class="col-md-6">
                                <strong><i class="fas fa-tag me-1"></i>Discount Type:</strong><br>
                                <?php if ($booking['discount_type'] !== 'regular'): ?>
                                    <span class="discount-badge">
                                        <i class="fas fa-percent me-1"></i>
                                        <?php echo ucfirst($booking['discount_type']); ?> (20% Off)
                                    </span>
                                    <div class="verification-status">
                                        <div class="verification-icon <?php echo $booking['discount_verified'] ? 'verified' : 'pending-verification'; ?>">
                                            <i class="fas fa-<?php echo $booking['discount_verified'] ? 'check' : 'clock'; ?>"></i>
                                        </div>
                                        <small class="text-muted">
                                            ID <?php echo $booking['discount_verified'] ? 'Verified' : 'Pending Verification'; ?>
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Regular Fare</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong><i class="fas fa-credit-card me-1"></i>Payment Method:</strong><br>
                            <?php 
                            $payment_display = getPaymentMethodDisplay($booking['payment_method']);
                            ?>
                            <span class="badge bg-info">
                                <i class="fas fa-<?php echo $payment_display['icon']; ?> me-1"></i>
                                <?php echo $payment_display['text']; ?>
                            </span>
                            
                            <?php if ($booking['payment_method'] !== 'counter'): ?>
                            <div class="verification-status">
                                <div class="verification-icon <?php echo $booking['payment_status'] === 'confirmed' ? 'verified' : 'pending-verification'; ?>">
                                    <i class="fas fa-<?php echo $booking['payment_status'] === 'confirmed' ? 'check' : 'clock'; ?>"></i>
                                </div>
                                <small class="text-muted">
                                    Payment <?php echo ucwords(str_replace('_', ' ', $booking['payment_status'])); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="fare-breakdown">
                            <div class="row align-items-center">
                                <div class="col-6">
                                    <div class="mb-2">
                                        <small class="text-muted d-block">Base Fare:</small>
                                        <span class="<?php echo $booking['discount_type'] !== 'regular' ? 'text-decoration-line-through text-muted' : 'fw-bold'; ?>">
                                            ₱<?php echo number_format($booking['base_fare'], 2); ?>
                                        </span>
                                    </div>
                                    <?php if ($booking['discount_amount'] > 0): ?>
                                    <div class="mb-2">
                                        <small class="text-muted d-block">Discount (<?php echo ucfirst($booking['discount_type']); ?>):</small>
                                        <span class="text-success fw-bold">
                                            -₱<?php echo number_format($booking['discount_amount'], 2); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6 text-end">
                                    <small class="text-muted d-block">Final Fare:</small>
                                    <div class="fw-bold text-success fs-4">
                                        ₱<?php echo number_format($booking['final_fare'], 2); ?>
                                    </div>
                                    <?php if ($booking['discount_amount'] > 0): ?>
                                    <div class="savings-highlight mt-2">
                                        <i class="fas fa-piggy-bank me-1"></i>
                                        You saved ₱<?php echo number_format($booking['discount_amount'], 2); ?>!
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Total Section -->
                <div class="total-section">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h3 class="mb-2">
                                <i class="fas fa-calculator me-2"></i>Total Amount
                            </h3>
                            <div class="row">
                                <div class="col-sm-6">
                                    <p class="mb-1 opacity-75">
                                        <i class="fas fa-tickets me-1"></i>
                                        <?php echo count($bookings); ?> ticket<?php echo count($bookings) > 1 ? 's' : ''; ?> booked
                                    </p>
                                </div>
                                <?php if ($total_savings > 0): ?>
                                <div class="col-sm-6">
                                    <p class="mb-1 opacity-75">
                                        <i class="fas fa-piggy-bank me-1"></i>
                                        Total savings: ₱<?php echo number_format($total_savings, 2); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-5 text-end">
                            <h1 class="mb-0 display-4">₱<?php echo number_format($total_fare, 2); ?></h1>
                            <?php if ($total_savings > 0): ?>
                            <small class="opacity-75">
                                (Original: ₱<?php echo number_format($total_fare + $total_savings, 2); ?>)
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- QR Code Section -->
                <div class="qr-code-section">
                    <h6 class="mb-3">
                        <i class="fas fa-qrcode me-2"></i>Booking QR Code
                    </h6>
                    <div id="qrcode" class="d-inline-block"></div>
                    <p class="mt-3 text-muted">
                        <i class="fas fa-mobile-alt me-1"></i>
                        Show this QR code to the conductor when boarding the bus
                    </p>
                </div> 

                <!-- Important Notes -->
                <div class="important-notes">
                    <h6 class="text-warning mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>Important Boarding Instructions
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="mb-0 small">
                                <li><strong>Arrival Time:</strong> Please arrive at the terminal at least 15 minutes before departure</li>
                                <li><strong>Required Documents:</strong> Present this receipt and a valid government ID when boarding</li>
                                <li><strong>Seat Assignment:</strong> Your seat number is reserved until departure time</li>
                                <?php if (in_array('counter', array_column($bookings, 'payment_method'))): ?>
                                <li><strong class="text-danger">Counter Payment Required:</strong> Please complete payment at the terminal before boarding</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="mb-0 small">
                                <?php if (array_filter($bookings, function($b) { return !$b['discount_verified'] && $b['discount_type'] !== 'regular'; })): ?>
                                <li><strong class="text-warning">Discount Verification Pending:</strong> You may be charged the full fare if ID verification fails</li>
                                <?php endif; ?>
                                <li><strong>Cancellation Policy:</strong> Cancellations are subject to terminal policies and fees</li>
                                <li><strong>Lost Receipt:</strong> Keep this receipt safe - it's required for boarding and refunds</li>
                                <li><strong>Contact:</strong> For assistance, contact the terminal or visit our help desk</li>
                            </ul>
                        </div>
                    </div>
                    
                    <?php 
                    $pending_payments = array_filter($bookings, function($b) { 
                        return in_array($b['payment_method'], ['gcash', 'paymaya']) && $b['payment_status'] === 'awaiting_verification'; 
                    });
                    if (!empty($pending_payments)): 
                    ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-clock me-2"></i>
                        <strong>Payment Verification Required:</strong> 
                        Some of your payments are still being verified. Please ensure payment verification is complete before traveling.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Booking Summary for Records -->
                <div class="mt-4 p-3 bg-light rounded">
                    <small class="text-muted">
                        <strong>Booking Summary:</strong> 
                        <?php echo count($bookings); ?> passenger<?php echo count($bookings) > 1 ? 's' : ''; ?> • 
                        <?php echo htmlspecialchars($route_info['origin']); ?> → <?php echo htmlspecialchars($route_info['destination']); ?> • 
                        <?php echo date('M d, Y', strtotime($bookings[0]['booking_date'])); ?> • 
                        Seats: <?php echo implode(', ', array_column($bookings, 'seat_number')); ?> • 
                        Total: ₱<?php echo number_format($total_fare, 2); ?>
                        <?php if (!empty($bookings[0]['group_booking_id'])): ?>
                        • Group: <?php echo htmlspecialchars($bookings[0]['group_booking_id']); ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="print-section no-print">
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <button onclick="window.print()" class="btn btn-primary btn-lg">
                    <i class="fas fa-print me-2"></i>Print Receipt
                </button>
                <button onclick="downloadReceipt()" class="btn btn-success btn-lg">
                    <i class="fas fa-download me-2"></i>Download PDF
                </button>
                <a href="../mybookings.php" class="btn btn-info btn-lg">
                    <i class="fas fa-list me-2"></i>My Bookings
                </a>
                <a href="../booking.php" class="btn btn-outline-primary btn-lg">
                    <i class="fas fa-plus me-2"></i>Book Another Trip
                </a>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script>
        // Generate comprehensive QR Code with booking information
        document.addEventListener('DOMContentLoaded', function() {
            const bookingData = <?php echo json_encode($bookings); ?>;
            const busInfo = <?php echo json_encode($bus_info); ?>;
            const routeInfo = <?php echo json_encode($route_info); ?>;
            
            const qrData = {
                type: 'ceres_bus_booking',
                version: '2.0',
                bookings: bookingData.map(booking => ({
                    reference: booking.booking_reference,
                    seat: booking.seat_number,
                    passenger: booking.passenger_name || (booking.first_name + ' ' + booking.last_name),
                    discount: booking.discount_type,
                    fare: booking.final_fare
                })),
                trip: {
                    date: bookingData[0].booking_date,
                    origin: routeInfo.origin,
                    destination: routeInfo.destination,
                    departure: busInfo.departure_time,
                    bus_plate: busInfo.plate_number,
                    trip_number: busInfo.trip_number
                },
                totals: {
                    passengers: bookingData.length,
                    total_fare: <?php echo $total_fare; ?>,
                    total_savings: <?php echo $total_savings; ?>
                },
                verification: {
                    generated_at: new Date().toISOString(),
                    receipt_url: window.location.href
                }
                <?php if (!empty($bookings[0]['group_booking_id'])): ?>
                ,group_id: '<?php echo htmlspecialchars($bookings[0]['group_booking_id']); ?>'
                <?php endif; ?>
            };
            
            QRCode.toCanvas(document.getElementById('qrcode'), JSON.stringify(qrData), {
                width: 200,
                height: 200,
                margin: 2,
                color: {
                    dark: '#000000',
                    light: '#FFFFFF'
                },
                errorCorrectionLevel: 'M'
            }, function (error) {
                if (error) {
                    console.error('QR Code generation failed:', error);
                    document.getElementById('qrcode').innerHTML = 
                        '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>QR Code could not be generated</div>';
                } else {
                    console.log('QR Code generated successfully');
                }
            });
        });

        // Download receipt as PDF (using browser's print to PDF)
        function downloadReceipt() {
            window.print();
        }

        // Share receipt functionality
        function shareReceipt(method) {
            const receiptUrl = window.location.href;
            const shareText = `My bus booking receipt for <?php echo htmlspecialchars($route_info['origin']); ?> to <?php echo htmlspecialchars($route_info['destination']); ?> on <?php echo date('M d, Y', strtotime($bookings[0]['booking_date'])); ?>`;
            
            switch(method) {
                case 'email':
                    const emailSubject = encodeURIComponent('Bus Booking Receipt - ISAT-U Ceres');
                    const emailBody = encodeURIComponent(`${shareText}\n\nReceipt: ${receiptUrl}`);
                    window.open(`mailto:?subject=${emailSubject}&body=${emailBody}`);
                    break;
                    
                case 'sms':
                    const smsText = encodeURIComponent(`${shareText} ${receiptUrl}`);
                    window.open(`sms:?body=${smsText}`);
                    break;
                    
                case 'copy':
                    navigator.clipboard.writeText(receiptUrl).then(() => {
                        alert('Receipt link copied to clipboard!');
                    }).catch(() => {
                        // Fallback for older browsers
                        prompt('Copy this link:', receiptUrl);
                    });
                    break;
            }
        }

        // Auto-focus and scroll to important elements
        document.addEventListener('DOMContentLoaded', function() {
            // Highlight any pending verifications
            const pendingElements = document.querySelectorAll('.pending-verification');
            if (pendingElements.length > 0) {
                console.log('Found pending verifications - user should be aware');
            }
            
            // Add click handlers for verification status elements
            document.querySelectorAll('.verification-status').forEach(element => {
                element.addEventListener('click', function() {
                    const status = this.querySelector('.verification-icon').classList;
                    if (status.contains('pending-verification')) {
                        alert('This verification is still pending. Please check back later or contact support if needed.');
                    } else if (status.contains('verified')) {
                        alert('This has been successfully verified.');
                    }
                });
                
                // Add hover effect
                element.style.cursor = 'pointer';
                element.title = 'Click for verification details';
            });
        });

        // Print event listener
        window.addEventListener('beforeprint', function() {
            console.log('Receipt being printed/downloaded');
        });

        // Page visibility API to track when user returns to check status
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page became visible - could check for verification updates here
                console.log('Receipt page became visible - user returned');
            }
        });
    </script>
</body>
</html>