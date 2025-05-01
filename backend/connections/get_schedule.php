<?php

require_once "config.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die(json_encode(['error' => 'Database connection not established']));
}

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Set response header to JSON
header('Content-Type: application/json');

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die(json_encode(['error' => 'Schedule ID is required']));
}

$schedule_id = intval($_GET['id']);

// Fetch schedule data
try {
    $query = "SELECT * FROM schedules WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $schedule = $result->fetch_assoc();
        
        // Format times for proper form display
        $schedule['departure_time'] = date('H:i', strtotime($schedule['departure_time']));
        $schedule['arrival_time'] = date('H:i', strtotime($schedule['arrival_time']));
        
        echo json_encode($schedule);
    } else {
        echo json_encode(['error' => 'Schedule not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Error retrieving schedule: ' . $e->getMessage()]);
}
?>