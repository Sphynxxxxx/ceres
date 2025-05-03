<?php
// Database connection
require_once "../../backend/connections/config.php";

header('Content-Type: application/json');

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$user_id = intval($_GET['user_id']);

// Get user details
$user_query = "SELECT id, first_name, last_name, gender, birthdate, contact_number, email, created_at 
               FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$user = $user_result->fetch_assoc();
$stmt->close();

// Get booking history
$booking_query = "SELECT 
    b.id,
    b.booking_reference,
    b.booking_date,
    b.booking_status,
    b.payment_status,
    b.payment_method,
    b.cancel_reason,
    b.cancelled_at,
    b.discount_type,
    b.trip_number,
    b.seat_number,
    bus.origin,
    bus.destination,
    bus.bus_type,
    bus.plate_number,
    s.departure_time,
    s.arrival_time,
    s.fare_amount
FROM bookings b
LEFT JOIN buses bus ON b.bus_id = bus.id
LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
WHERE b.user_id = ?
ORDER BY b.created_at DESC";

$stmt = $conn->prepare($booking_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$booking_result = $stmt->get_result();

$bookings = [];
while ($row = $booking_result->fetch_assoc()) {
    $bookings[] = $row;
}
$stmt->close();

// Return JSON response
echo json_encode([
    'success' => true,
    'user' => $user,
    'bookings' => $bookings
]);
?>