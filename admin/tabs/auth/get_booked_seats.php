<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../../backend/connections/config.php";

// Check if bus ID is provided
if (!isset($_GET['bus_id']) || !is_numeric($_GET['bus_id'])) {
    echo json_encode(['error' => 'Invalid bus ID']);
    exit;
}

$bus_id = intval($_GET['bus_id']);
$booking_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Debugging: Log the parameters
error_log("Bus ID: $bus_id, Date: $booking_date");

// Query to get booked seats for the specific bus and date
$query = "SELECT seat_number 
          FROM bookings 
          WHERE bus_id = ? 
          AND DATE(booking_date) = ? 
          AND booking_status = 'confirmed'";

$stmt = $conn->prepare($query);
$stmt->bind_param("is", $bus_id, $booking_date);
$stmt->execute();
$result = $stmt->get_result();

$bookedSeats = [];
while ($row = $result->fetch_assoc()) {
    $bookedSeats[] = intval($row['seat_number']);
}

// Debugging: Log booked seats
error_log("Booked Seats: " . json_encode($bookedSeats));

$stmt->close();
$conn->close();

echo json_encode(['bookedSeats' => $bookedSeats]);
exit;
?>