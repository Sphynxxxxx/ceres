<?php
/**
 * API endpoint to fetch booked seats for a specific bus and date
 * Synced with unified booking system and database schema
 * Place this file in: ../../backend/connections/get_booked_seats.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
require_once "config.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection not established',
        'bookedSeats' => []
    ]);
    exit;
}

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Connection failed: ' . $conn->connect_error,
        'bookedSeats' => []
    ]);
    exit;
}

// Get parameters
$bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

// Debug logging
error_log("get_booked_seats.php called with bus_id: $bus_id, date: $date");

// Validate parameters
if ($bus_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid bus ID',
        'bookedSeats' => []
    ]);
    exit;
}

if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid date format. Use YYYY-MM-DD',
        'bookedSeats' => []
    ]);
    exit;
}

try {
    // Enhanced query to fetch comprehensive booking information
    $query = "SELECT 
                b.id as booking_id,
                b.seat_number, 
                b.passenger_name, 
                b.discount_type, 
                b.discount_verified,
                b.booking_reference, 
                b.booking_status,
                b.payment_method,
                b.payment_status,
                b.group_booking_id,
                b.base_fare,
                b.discount_amount,
                b.final_fare,
                b.created_at,
                b.updated_at,
                u.first_name,
                u.last_name,
                u.email,
                u.contact_number
              FROM bookings b
              LEFT JOIN users u ON b.user_id = u.id
              WHERE b.bus_id = ? 
                AND DATE(b.booking_date) = ? 
                AND b.booking_status = 'confirmed' 
              ORDER BY b.seat_number";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("is", $bus_id, $date);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $booked_seats = [];
    $seat_details = [];
    $group_bookings = [];
    $total_revenue = 0;
    $total_discount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $seat_number = (int)$row['seat_number'];
        $booked_seats[] = $seat_number;
        
        // Calculate totals
        $total_revenue += floatval($row['final_fare'] ?? 0);
        $total_discount += floatval($row['discount_amount'] ?? 0);
        
        // Detailed seat information
        $seat_details[$seat_number] = [
            'booking_id' => $row['booking_id'],
            'passenger_name' => $row['passenger_name'],
            'discount_type' => $row['discount_type'],
            'discount_verified' => (bool)$row['discount_verified'],
            'booking_reference' => $row['booking_reference'],
            'booking_status' => $row['booking_status'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'group_booking_id' => $row['group_booking_id'],
            'base_fare' => floatval($row['base_fare'] ?? 0),
            'discount_amount' => floatval($row['discount_amount'] ?? 0),
            'final_fare' => floatval($row['final_fare'] ?? 0),
            'booked_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'booker_info' => [
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'contact_number' => $row['contact_number']
            ]
        ];
        
        // Group booking tracking
        if (!empty($row['group_booking_id'])) {
            if (!isset($group_bookings[$row['group_booking_id']])) {
                $group_bookings[$row['group_booking_id']] = [
                    'seats' => [],
                    'total_passengers' => 0,
                    'total_amount' => 0,
                    'payment_method' => $row['payment_method'],
                    'payment_status' => $row['payment_status']
                ];
            }
            $group_bookings[$row['group_booking_id']]['seats'][] = $seat_number;
            $group_bookings[$row['group_booking_id']]['total_passengers']++;
            $group_bookings[$row['group_booking_id']]['total_amount'] += floatval($row['final_fare'] ?? 0);
        }
    }
    
    error_log("Found " . count($booked_seats) . " booked seats for bus $bus_id on $date");
    
    // Get comprehensive bus information
    $bus_query = "SELECT 
                    b.id,
                    b.seat_capacity, 
                    b.bus_type, 
                    b.plate_number,
                    b.driver_name,
                    b.conductor_name,
                    b.route_name,
                    b.status,
                    s.departure_time,
                    s.arrival_time,
                    s.trip_number,
                    r.fare as route_fare,
                    r.distance,
                    r.estimated_duration
                  FROM buses b
                  LEFT JOIN schedules s ON b.id = s.bus_id
                  LEFT JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                  WHERE b.id = ?
                  LIMIT 1";
    
    $bus_stmt = $conn->prepare($bus_query);
    $bus_stmt->bind_param("i", $bus_id);
    $bus_stmt->execute();
    $bus_result = $bus_stmt->get_result();
    
    $bus_info = null;
    if ($bus_result->num_rows > 0) {
        $bus_info = $bus_result->fetch_assoc();
        // Format times if available
        if ($bus_info['departure_time']) {
            $bus_info['departure_time_formatted'] = date('h:i A', strtotime($bus_info['departure_time']));
        }
        if ($bus_info['arrival_time']) {
            $bus_info['arrival_time_formatted'] = date('h:i A', strtotime($bus_info['arrival_time']));
        }
    }
    
    // Calculate availability statistics
    $available_seats = 0;
    $occupancy_rate = 0;
    $capacity = 0;
    
    if ($bus_info) {
        $capacity = (int)$bus_info['seat_capacity'];
        $available_seats = $capacity - count($booked_seats);
        $occupancy_rate = $capacity > 0 ? round((count($booked_seats) / $capacity) * 100, 2) : 0;
    }
    
    // Check for any pending bookings that might affect availability
    $pending_query = "SELECT COUNT(*) as pending_count 
                      FROM bookings 
                      WHERE bus_id = ? 
                        AND DATE(booking_date) = ? 
                        AND booking_status IN ('pending', 'confirmed')";
    
    $pending_stmt = $conn->prepare($pending_query);
    $pending_stmt->bind_param("is", $bus_id, $date);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result();
    $pending_data = $pending_result->fetch_assoc();
    $pending_bookings = (int)$pending_data['pending_count'];
    
    // Get discount statistics
    $discount_stats = [
        'regular' => 0,
        'student' => 0,
        'senior' => 0,
        'pwd' => 0
    ];
    
    foreach ($seat_details as $seat_detail) {
        $discount_type = $seat_detail['discount_type'] ?? 'regular';
        if (isset($discount_stats[$discount_type])) {
            $discount_stats[$discount_type]++;
        }
    }
    
    // Prepare comprehensive response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'bus_id' => $bus_id,
        'date' => $date,
        'bookedSeats' => $booked_seats,
        'seatDetails' => $seat_details,
        'groupBookings' => $group_bookings,
        'statistics' => [
            'total_booked' => count($booked_seats),
            'available_seats' => $available_seats,
            'capacity' => $capacity,
            'occupancy_rate' => $occupancy_rate,
            'pending_bookings' => $pending_bookings,
            'total_revenue' => round($total_revenue, 2),
            'total_discount' => round($total_discount, 2),
            'discount_breakdown' => $discount_stats
        ],
        'busInfo' => $bus_info,
        'seat_map' => [
            'total_seats' => $capacity,
            'booked_seats' => $booked_seats,
            'available_seats' => array_diff(range(1, $capacity), $booked_seats)
        ]
    ];
    
    // Add warning if bus is nearly full
    if ($occupancy_rate >= 90) {
        $response['warnings'] = ['Bus is nearly full (' . $occupancy_rate . '% occupied)'];
    }
    
    // Add payment verification status for online payments
    $verification_needed = 0;
    foreach ($seat_details as $seat_detail) {
        if (in_array($seat_detail['payment_method'], ['gcash', 'paymaya']) && 
            $seat_detail['payment_status'] === 'awaiting_verification') {
            $verification_needed++;
        }
    }
    
    if ($verification_needed > 0) {
        $response['admin_notes'] = [
            'payments_awaiting_verification' => $verification_needed
        ];
    }
    
    error_log("Returning seat data: " . count($booked_seats) . " booked seats");
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error fetching booked seats: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch booked seats',
        'message' => $e->getMessage(),
        'bookedSeats' => [], // Always provide this for JavaScript compatibility
        'statistics' => [
            'total_booked' => 0,
            'available_seats' => 0,
            'capacity' => 0,
            'occupancy_rate' => 0
        ]
    ]);
} finally {
    // Clean up prepared statements
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($bus_stmt)) {
        $bus_stmt->close();
    }
    if (isset($pending_stmt)) {
        $pending_stmt->close();
    }
}
?>