<?php
// Database connection
require_once "config.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die(json_encode(['error' => 'Database connection not established']));
}

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die(json_encode(['error' => 'Schedule ID is required']));
}

$schedule_id = intval($_GET['id']);

try {
    // Get schedule data - don't assume seat_capacity exists
    $stmt = $conn->prepare("SELECT s.*, b.plate_number, b.bus_type 
                           FROM schedules s 
                           JOIN buses b ON s.bus_id = b.id 
                           WHERE s.id = ?");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $schedule = $result->fetch_assoc();
        
        // Format times for the edit form
        $schedule['departure_time'] = date('H:i', strtotime($schedule['departure_time']));
        $schedule['arrival_time'] = date('H:i', strtotime($schedule['arrival_time']));
        
        // Return the schedule data as JSON
        echo json_encode($schedule);
    } else {
        echo json_encode(['error' => 'Schedule not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>