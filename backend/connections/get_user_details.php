<?php
header('Content-Type: application/json');

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "config.php";

// Check if user_id is provided
if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

$user_id = intval($_GET['user_id']);

// Get user details
$user_query = "SELECT id, first_name, last_name, gender, birthdate, contact_number, email, created_at, is_verified 
              FROM users 
              WHERE id = ?";

$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$user = $user_result->fetch_assoc();

// Get user's booking history
$booking_query = "SELECT 
                    b.id,
                    b.booking_reference,
                    b.booking_date,
                    b.booking_status,
                    b.payment_status,
                    s.origin,
                    s.destination,
                    s.fare_amount
                FROM bookings b
                JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                WHERE b.user_id = ?
                ORDER BY b.booking_date DESC
                LIMIT 50";

$stmt = $conn->prepare($booking_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$booking_result = $stmt->get_result();

$bookings = [];
while ($row = $booking_result->fetch_assoc()) {
    $bookings[] = $row;
}

// Return the data
echo json_encode([
    'success' => true,
    'user' => $user,
    'bookings' => $bookings
]);

$conn->close();
?>