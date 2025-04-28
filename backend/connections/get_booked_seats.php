<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Return error as JSON
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Set headers
header('Content-Type: application/json');

// Check if required parameters are provided
if (!isset($_GET['bus_id']) || !isset($_GET['date'])) {
    echo json_encode([
        'error' => 'Missing required parameters',
        'bookedSeats' => []
    ]);
    exit;
}

// Get parameters
$bus_id = intval($_GET['bus_id']);
$date = $_GET['date'];

// Validate parameters
if ($bus_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode([
        'error' => 'Invalid parameters',
        'bookedSeats' => []
    ]);
    exit;
}

// Database connection
require_once "config.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode([
        'error' => 'Database connection not established',
        'bookedSeats' => []
    ]);
    exit;
}

if ($conn->connect_error) {
    echo json_encode([
        'error' => 'Connection failed: ' . $conn->connect_error,
        'bookedSeats' => []
    ]);
    exit;
}

// Fetch booked seats
$booked_seats = [];
try {
    $query = "SELECT seat_number FROM bookings 
              WHERE bus_id = ? AND booking_date = ? AND booking_status = 'confirmed'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $bus_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $booked_seats[] = (int)$row['seat_number'];
    }
    
    // Return the booked seats
    echo json_encode([
        'busId' => $bus_id,
        'date' => $date,
        'bookedSeats' => $booked_seats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error fetching booked seats: ' . $e->getMessage(),
        'bookedSeats' => []
    ]);
}
?>