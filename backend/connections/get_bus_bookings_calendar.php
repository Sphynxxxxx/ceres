<?php
// File: backend/connections/get_bus_bookings_calendar.php
session_start();

// Include database connection
require_once "config.php";

// Response array
$response = [];

// Input validation
$bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
$start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end_date = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+1 month'));

// Validate inputs
if ($bus_id <= 0) {
    echo json_encode(['error' => 'Invalid bus ID']);
    exit;
}

try {
    // Prepare SQL to get bookings for specific bus within date range
    $query = "SELECT 
                id as booking_id, 
                booking_date, 
                booking_status 
              FROM bookings 
              WHERE bus_id = ? 
              AND booking_date BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $bus_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Collect bookings
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($bookings);
    
    // Close statement
    $stmt->close();
} catch (Exception $e) {
    // Send error response
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
?>