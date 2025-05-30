<?php
// get_booked_seats.php - API endpoint for fetching booked seats
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
require_once "config.php";

// Check if connection exists
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not established']);
    exit;
}

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
    exit;
}

// Get parameters
$bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';
$trip_number = isset($_GET['trip_number']) ? $_GET['trip_number'] : '';

// Validate parameters
if ($bus_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid bus ID']);
    exit;
}

if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Validate that the date is not in the past (optional check)
if (strtotime($date) < strtotime(date('Y-m-d'))) {
    // Allow past dates for viewing historical bookings, but add flag
    $isPastDate = true;
} else {
    $isPastDate = false;
}

try {
    // Build query with optional trip number filter
    $queryConditions = "bus_id = ? AND DATE(booking_date) = ? AND booking_status = 'confirmed'";
    $queryParams = [$bus_id, $date];
    $paramTypes = "is";
    
    if (!empty($trip_number)) {
        $queryConditions .= " AND trip_number = ?";
        $queryParams[] = $trip_number;
        $paramTypes .= "s";
    }
    
    // Fetch booked seats for the given bus and date
    $query = "SELECT seat_number, passenger_name, booking_reference, booking_status, 
                     payment_status, created_at, trip_number, ticket_group_id,
                     discount_type, passenger_age
              FROM bookings 
              WHERE $queryConditions
              ORDER BY seat_number ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($paramTypes, ...$queryParams);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookedSeats = [];
    $seatDetails = [];
    $groupedBookings = [];
    
    while ($row = $result->fetch_assoc()) {
        $seatNumber = (int)$row['seat_number'];
        $bookedSeats[] = $seatNumber;
        
        $seatDetail = [
            'seat_number' => $seatNumber,
            'passenger_name' => $row['passenger_name'] ?? 'Not specified',
            'passenger_age' => $row['passenger_age'] ? (int)$row['passenger_age'] : null,
            'booking_reference' => $row['booking_reference'],
            'booking_status' => $row['booking_status'],
            'payment_status' => $row['payment_status'],
            'trip_number' => $row['trip_number'],
            'discount_type' => $row['discount_type'] ?? 'regular',
            'booked_at' => $row['created_at'],
            'ticket_group_id' => $row['ticket_group_id']
        ];
        
        $seatDetails[] = $seatDetail;
        
        // Group bookings by ticket_group_id for multiple bookings
        if (!empty($row['ticket_group_id'])) {
            if (!isset($groupedBookings[$row['ticket_group_id']])) {
                $groupedBookings[$row['ticket_group_id']] = [];
            }
            $groupedBookings[$row['ticket_group_id']][] = $seatDetail;
        }
    }
    
    // Get bus information
    $busQuery = "SELECT seat_capacity, bus_type, plate_number, driver_name, conductor_name, route_name FROM buses WHERE id = ?";
    $busStmt = $conn->prepare($busQuery);
    $busStmt->bind_param("i", $bus_id);
    $busStmt->execute();
    $busResult = $busStmt->get_result();
    
    $busInfo = null;
    if ($busResult->num_rows > 0) {
        $busInfo = $busResult->fetch_assoc();
        $busInfo['seat_capacity'] = (int)$busInfo['seat_capacity'];
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Bus not found']);
        exit;
    }
    
    // Get schedule information for this bus and date
    $scheduleQuery = "SELECT departure_time, arrival_time, trip_number, fare_amount 
                      FROM schedules 
                      WHERE bus_id = ? AND status = 'active'
                      AND (recurring = 1 OR (recurring = 0 AND date = ?))";
    
    if (!empty($trip_number)) {
        $scheduleQuery .= " AND trip_number = ?";
        $scheduleStmt = $conn->prepare($scheduleQuery);
        $scheduleStmt->bind_param("iss", $bus_id, $date, $trip_number);
    } else {
        $scheduleStmt = $conn->prepare($scheduleQuery);
        $scheduleStmt->bind_param("is", $bus_id, $date);
    }
    
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();
    
    $scheduleInfo = [];
    while ($scheduleRow = $scheduleResult->fetch_assoc()) {
        $scheduleInfo[] = [
            'trip_number' => $scheduleRow['trip_number'],
            'departure_time' => date('h:i A', strtotime($scheduleRow['departure_time'])),
            'arrival_time' => date('h:i A', strtotime($scheduleRow['arrival_time'])),
            'fare_amount' => (float)$scheduleRow['fare_amount']
        ];
    }
    
    // Calculate availability
    $totalSeats = $busInfo['seat_capacity'];
    $availableSeats = $totalSeats - count($bookedSeats);
    $occupancyRate = $totalSeats > 0 ? round((count($bookedSeats) / $totalSeats) * 100, 2) : 0;
    
    // Calculate revenue statistics
    $totalRevenue = 0;
    $passengerCount = count($seatDetails);
    $groupBookingCount = count($groupedBookings);
    $singleBookingCount = $passengerCount - array_sum(array_map('count', $groupedBookings));
    
    // Get fare information for revenue calculation
    if (!empty($scheduleInfo)) {
        $fareAmount = $scheduleInfo[0]['fare_amount'];
        foreach ($seatDetails as $seat) {
            $fareForSeat = $fareAmount;
            // Apply discount if applicable
            if ($seat['discount_type'] !== 'regular') {
                $fareForSeat = $fareAmount * 0.8; // 20% discount
            }
            $totalRevenue += $fareForSeat;
        }
    }
    
    // Response
    $response = [
        'success' => true,
        'bus_id' => $bus_id,
        'date' => $date,
        'trip_number' => $trip_number,
        'is_past_date' => $isPastDate,
        'bookedSeats' => $bookedSeats,
        'seatDetails' => $seatDetails,
        'groupedBookings' => $groupedBookings,
        'scheduleInfo' => $scheduleInfo,
        'busInfo' => $busInfo,
        'statistics' => [
            'totalSeats' => $totalSeats,
            'availableSeats' => $availableSeats,
            'bookedSeats' => count($bookedSeats),
            'occupancyRate' => $occupancyRate,
            'passengerCount' => $passengerCount,
            'singleBookings' => $singleBookingCount,
            'groupBookings' => $groupBookingCount,
            'estimatedRevenue' => round($totalRevenue, 2)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error fetching booked seats: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'Failed to fetch booked seats',
        'debug' => [
            'bus_id' => $bus_id,
            'date' => $date,
            'trip_number' => $trip_number
        ]
    ]);
}

$conn->close();
?>