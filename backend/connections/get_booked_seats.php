<?php
/**
 * API endpoint to fetch booked seats for a specific bus and date
 * Compatible with unified multi-ticket booking system
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
    // First, check what columns exist in the bookings table
    $columns_check = "SHOW COLUMNS FROM bookings";
    $columns_result = $conn->query($columns_check);
    $available_columns = [];
    
    if ($columns_result) {
        while ($row = $columns_result->fetch_assoc()) {
            $available_columns[] = $row['Field'];
        }
    }
    
    // Build query based on available columns
    $base_columns = [
        'b.id as booking_id',
        'b.seat_number', 
        'b.booking_reference', 
        'b.booking_status',
        'b.created_at'
    ];
    
    // Add optional columns if they exist
    $optional_columns = [
        'passenger_name' => 'b.passenger_name',
        'discount_type' => 'b.discount_type',
        'discount_verified' => 'b.discount_verified',
        'payment_method' => 'b.payment_method',
        'payment_status' => 'b.payment_status',
        'group_booking_id' => 'b.group_booking_id',
        'base_fare' => 'b.base_fare',
        'discount_amount' => 'b.discount_amount',
        'final_fare' => 'b.final_fare',
        'updated_at' => 'b.updated_at',
        'trip_number' => 'b.trip_number' // Added for better trip tracking
    ];
    
    $select_columns = $base_columns;
    $existing_optional_columns = [];
    
    foreach ($optional_columns as $column_name => $column_query) {
        if (in_array($column_name, $available_columns)) {
            $select_columns[] = $column_query;
            $existing_optional_columns[] = $column_name;
        }
    }
    
    // Add user columns if users table exists
    $user_columns = [];
    $user_table_exists = false;
    
    $tables_check = "SHOW TABLES LIKE 'users'";
    $tables_result = $conn->query($tables_check);
    if ($tables_result && $tables_result->num_rows > 0) {
        $user_table_exists = true;
        $user_columns = [
            'u.first_name',
            'u.last_name', 
            'u.email',
            'u.contact_number'
        ];
        $select_columns = array_merge($select_columns, $user_columns);
    }
    
    // Build the main query - IMPROVED: Added trip_number condition for better accuracy
    $query = "SELECT " . implode(', ', $select_columns) . "
              FROM bookings b";
    
    if ($user_table_exists) {
        $query .= " LEFT JOIN users u ON b.user_id = u.id";
    }
    
    $query .= " WHERE b.bus_id = ? 
                AND DATE(b.booking_date) = ? 
                AND b.booking_status = 'confirmed'";
    
    // Add trip number filter if available and specified
    $trip_number = isset($_GET['trip_number']) ? $_GET['trip_number'] : '';
    $use_trip_filter = false;
    
    if (!empty($trip_number) && in_array('trip_number', $available_columns)) {
        $query .= " AND b.trip_number = ?";
        $use_trip_filter = true;
    }
    
    $query .= " ORDER BY b.seat_number";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // Bind parameters based on whether trip filter is used
    if ($use_trip_filter) {
        $stmt->bind_param("iss", $bus_id, $date, $trip_number);
    } else {
        $stmt->bind_param("is", $bus_id, $date);
    }
    
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
        
        // Calculate totals with safe fallbacks
        $final_fare = floatval($row['final_fare'] ?? $row['base_fare'] ?? 0);
        $discount_amount = floatval($row['discount_amount'] ?? 0);
        
        $total_revenue += $final_fare;
        $total_discount += $discount_amount;
        
        // Build seat details with safe fallbacks
        $seat_detail = [
            'booking_id' => $row['booking_id'],
            'passenger_name' => $row['passenger_name'] ?? 'N/A',
            'discount_type' => $row['discount_type'] ?? 'regular',
            'discount_verified' => isset($row['discount_verified']) ? (bool)$row['discount_verified'] : false,
            'booking_reference' => $row['booking_reference'],
            'booking_status' => $row['booking_status'],
            'payment_method' => $row['payment_method'] ?? 'counter',
            'payment_status' => $row['payment_status'] ?? 'pending',
            'base_fare' => floatval($row['base_fare'] ?? 0),
            'discount_amount' => $discount_amount,
            'final_fare' => $final_fare,
            'trip_number' => $row['trip_number'] ?? 'N/A', // Added trip tracking
            'booked_at' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? $row['created_at']
        ];
        
        // Add user info if available
        if ($user_table_exists) {
            $seat_detail['booker_info'] = [
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'email' => $row['email'] ?? '',
                'contact_number' => $row['contact_number'] ?? ''
            ];
        }
        
        // Add group booking info if available
        if (isset($row['group_booking_id']) && !empty($row['group_booking_id'])) {
            $seat_detail['group_booking_id'] = $row['group_booking_id'];
            
            if (!isset($group_bookings[$row['group_booking_id']])) {
                $group_bookings[$row['group_booking_id']] = [
                    'seats' => [],
                    'total_passengers' => 0,
                    'total_amount' => 0,
                    'payment_method' => $seat_detail['payment_method'],
                    'payment_status' => $seat_detail['payment_status'],
                    'trip_number' => $seat_detail['trip_number'] // Added for group tracking
                ];
            }
            $group_bookings[$row['group_booking_id']]['seats'][] = $seat_number;
            $group_bookings[$row['group_booking_id']]['total_passengers']++;
            $group_bookings[$row['group_booking_id']]['total_amount'] += $final_fare;
        }
        
        $seat_details[$seat_number] = $seat_detail;
    }
    
    error_log("Found " . count($booked_seats) . " booked seats for bus $bus_id on $date" . 
              ($use_trip_filter ? " (trip: $trip_number)" : ""));
    
    // Get bus information with safe fallbacks
    $bus_query = "SELECT 
                    b.id,
                    b.seat_capacity, 
                    b.bus_type, 
                    b.plate_number,
                    b.driver_name,
                    b.conductor_name,
                    b.route_name,
                    b.status";
    
    // Check if schedules table exists and has the needed columns
    $schedules_join = "";
    $schedule_tables_check = "SHOW TABLES LIKE 'schedules'";
    $schedule_tables_result = $conn->query($schedule_tables_check);
    
    if ($schedule_tables_result && $schedule_tables_result->num_rows > 0) {
        $schedule_columns_check = "SHOW COLUMNS FROM schedules";
        $schedule_columns_result = $conn->query($schedule_columns_check);
        $schedule_columns = [];
        
        if ($schedule_columns_result) {
            while ($col_row = $schedule_columns_result->fetch_assoc()) {
                $schedule_columns[] = $col_row['Field'];
            }
        }
        
        if (in_array('bus_id', $schedule_columns) && 
            in_array('departure_time', $schedule_columns) && 
            in_array('arrival_time', $schedule_columns)) {
            $bus_query .= ",
                    s.departure_time,
                    s.arrival_time,
                    s.trip_number";
            $schedules_join = " LEFT JOIN schedules s ON b.id = s.bus_id";
            
            // If specific trip requested, filter by it
            if ($use_trip_filter) {
                $schedules_join .= " AND s.trip_number = '$trip_number'";
            }
        }
    }
    
    // Check if routes table exists
    $routes_join = "";
    $routes_tables_check = "SHOW TABLES LIKE 'routes'";
    $routes_tables_result = $conn->query($routes_tables_check);
    
    if ($routes_tables_result && $routes_tables_result->num_rows > 0) {
        $routes_columns_check = "SHOW COLUMNS FROM routes";
        $routes_columns_result = $conn->query($routes_columns_check);
        $routes_columns = [];
        
        if ($routes_columns_result) {
            while ($col_row = $routes_columns_result->fetch_assoc()) {
                $routes_columns[] = $col_row['Field'];
            }
        }
        
        if (in_array('origin', $routes_columns) && 
            in_array('destination', $routes_columns) && 
            in_array('fare', $routes_columns)) {
            $bus_query .= ",
                    r.fare as route_fare,
                    r.distance,
                    r.estimated_duration";
            $routes_join = " LEFT JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)";
        }
    }
    
    $bus_query .= " FROM buses b" . $schedules_join . $routes_join . " WHERE b.id = ? LIMIT 1";
    
    $bus_stmt = $conn->prepare($bus_query);
    $bus_stmt->bind_param("i", $bus_id);
    $bus_stmt->execute();
    $bus_result = $bus_stmt->get_result();
    
    $bus_info = null;
    if ($bus_result->num_rows > 0) {
        $bus_info = $bus_result->fetch_assoc();
        
        // Format times if available
        if (isset($bus_info['departure_time']) && $bus_info['departure_time']) {
            $bus_info['departure_time_formatted'] = date('h:i A', strtotime($bus_info['departure_time']));
        }
        if (isset($bus_info['arrival_time']) && $bus_info['arrival_time']) {
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
    
    // IMPROVED: Check for pending/awaiting verification bookings separately
    $pending_bookings = 0;
    $awaiting_verification = 0;
    try {
        $status_query = "SELECT 
                           COUNT(CASE WHEN booking_status = 'pending' THEN 1 END) as pending_count,
                           COUNT(CASE WHEN payment_status = 'awaiting_verification' THEN 1 END) as verification_count
                         FROM bookings 
                         WHERE bus_id = ? 
                           AND DATE(booking_date) = ?";
        
        if ($use_trip_filter) {
            $status_query .= " AND trip_number = ?";
        }
        
        $status_stmt = $conn->prepare($status_query);
        if ($use_trip_filter) {
            $status_stmt->bind_param("iss", $bus_id, $date, $trip_number);
        } else {
            $status_stmt->bind_param("is", $bus_id, $date);
        }
        
        $status_stmt->execute();
        $status_result = $status_stmt->get_result();
        
        if ($status_result) {
            $status_data = $status_result->fetch_assoc();
            $pending_bookings = (int)($status_data['pending_count'] ?? 0);
            $awaiting_verification = (int)($status_data['verification_count'] ?? 0);
        }
    } catch (Exception $e) {
        error_log("Error fetching booking statuses: " . $e->getMessage());
    }
    
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
    
    // IMPROVED: Get available seat numbers in proper order
    $available_seat_numbers = [];
    if ($capacity > 0) {
        for ($i = 1; $i <= $capacity; $i++) {
            if (!in_array($i, $booked_seats)) {
                $available_seat_numbers[] = $i;
            }
        }
    }
    
    // Prepare comprehensive response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'bus_id' => $bus_id,
        'date' => $date,
        'trip_number' => $use_trip_filter ? $trip_number : 'all',
        'bookedSeats' => $booked_seats,
        'seatDetails' => $seat_details,
        'groupBookings' => $group_bookings,
        'statistics' => [
            'total_booked' => count($booked_seats),
            'available_seats' => $available_seats,
            'capacity' => $capacity,
            'occupancy_rate' => $occupancy_rate,
            'pending_bookings' => $pending_bookings,
            'awaiting_verification' => $awaiting_verification,
            'total_revenue' => round($total_revenue, 2),
            'total_discount' => round($total_discount, 2),
            'discount_breakdown' => $discount_stats
        ],
        'busInfo' => $bus_info,
        'seat_map' => [
            'total_seats' => $capacity,
            'booked_seats' => $booked_seats,
            'available_seats' => $available_seat_numbers
        ],
        'schema_info' => [
            'available_columns' => $existing_optional_columns,
            'user_table_exists' => $user_table_exists,
            'schedules_join_available' => !empty($schedules_join),
            'routes_join_available' => !empty($routes_join),
            'trip_filter_used' => $use_trip_filter
        ]
    ];
    
    // Add warnings and admin notes
    $warnings = [];
    $admin_notes = [];
    
    if ($occupancy_rate >= 90) {
        $warnings[] = 'Bus is nearly full (' . $occupancy_rate . '% occupied)';
    }
    
    if ($awaiting_verification > 0) {
        $admin_notes['payments_awaiting_verification'] = $awaiting_verification;
    }
    
    if ($pending_bookings > 0) {
        $admin_notes['pending_bookings'] = $pending_bookings;
    }
    
    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }
    
    if (!empty($admin_notes)) {
        $response['admin_notes'] = $admin_notes;
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
            'occupancy_rate' => 0,
            'pending_bookings' => 0,
            'awaiting_verification' => 0
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
    if (isset($status_stmt)) {
        $status_stmt->close();
    }
}
?>