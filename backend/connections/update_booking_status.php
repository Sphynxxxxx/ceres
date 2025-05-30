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

// Get form data
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';

// Validate required fields
if (!$booking_id || empty($status)) {
    $response['message'] = 'Missing required fields';
    echo json_encode($response);
    exit;
}

// Validate status
if (!in_array($status, ['confirmed', 'pending', 'cancelled'])) {
    $response['message'] = 'Invalid status';
    echo json_encode($response);
    exit;
}

// Handle payment proof upload (GCash or PayMaya)
$payment_proof_path = null;

if (isset($_FILES['gcash_payment_proof']) || isset($_FILES['paymaya_payment_proof'])) {
    $payment_method = $_POST['payment_method'];
    $file_key = $payment_method . '_payment_proof';

    if (!isset($_FILES[$file_key])) {
        $response['message'] = 'File input not found.';
        echo json_encode($response);
        exit;
    }

    $file = $_FILES[$file_key];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $response['message'] = 'File upload error.';
        echo json_encode($response);
        exit;
    }

    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png'];
    $file_type = mime_content_type($file['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        $response['message'] = 'Only JPG and PNG files are allowed.';
        echo json_encode($response);
        exit;
    }

    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        $response['message'] = 'File size exceeds 5MB.';
        echo json_encode($response);
        exit;
    }

    // Generate unique filename
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = $payment_method . '_' . uniqid() . '.' . $extension;
    $upload_dir = __DIR__ . '/../../uploads/payment_proofs/';
    $file_path = $upload_dir . $new_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        $response['message'] = 'Failed to move uploaded file.';
        echo json_encode($response);
        exit;
    }

    $payment_proof_path = 'payment_proofs/' . $new_filename;
}

// Update booking status
$conn->begin_transaction();

try {
    // Update booking status and optionally add payment proof
    if ($payment_proof_path) {
        $query = "UPDATE bookings SET booking_status = ?, payment_proof = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssi", $status, $payment_proof_path, $booking_id);
    } else {
        $query = "UPDATE bookings SET booking_status = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $status, $booking_id);
    }

    if (!$stmt->execute()) {
        throw new Exception("Failed to update booking status.");
    }

    // If cancelled, update cancelled_at timestamp
    if ($status === 'cancelled') {
        $query = "UPDATE bookings SET cancelled_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $booking_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to set cancellation time.");
        }
    }

    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Booking status updated successfully';
    if ($payment_proof_path) {
        $response['payment_proof'] = $payment_proof_path;
    }

} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);