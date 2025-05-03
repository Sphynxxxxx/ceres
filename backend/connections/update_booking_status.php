<?php

header('Content-Type: application/json');
require_once "config.php";

$response = ['success' => false, 'message' => ''];

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['booking_id']) || !isset($data['status'])) {
    $response['message'] = 'Missing required fields';
    echo json_encode($response);
    exit;
}

$booking_id = (int)$data['booking_id'];
$status = $data['status'];

// Validate status
if (!in_array($status, ['confirmed', 'pending', 'cancelled'])) {
    $response['message'] = 'Invalid status';
    echo json_encode($response);
    exit;
}

// Update booking status
$query = "UPDATE bookings SET booking_status = ? WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("si", $status, $booking_id);

if ($stmt->execute()) {
    // If status is cancelled, update cancelled_at timestamp
    if ($status === 'cancelled') {
        $query = "UPDATE bookings SET cancelled_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
    }
    
    $response['success'] = true;
    $response['message'] = 'Booking status updated successfully';
} else {
    $response['message'] = 'Failed to update booking status';
}

echo json_encode($response);