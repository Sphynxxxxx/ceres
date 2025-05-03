<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

require_once "config.php";

// Get the last event ID
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;

while (true) {
    // Check for new confirmed bookings
    $query = "SELECT id, created_at, booking_status FROM bookings 
              WHERE id > ? AND booking_status = 'confirmed'
              ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $lastEventId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $newBooking = $result->fetch_assoc();
        $lastEventId = $newBooking['id'];
        
        echo "data: " . json_encode($newBooking) . "\n\n";
        ob_flush();
        flush();
    }
    
    sleep(5); // Check every 5 seconds
}