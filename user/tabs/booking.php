<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Redirect to login page
    header("Location: login.php");
    exit;
}

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Database connection
require_once "../../backend/connections/config.php"; 
require_once "../../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$selected_origin = isset($_GET['origin']) ? urldecode($_GET['origin']) : '';
$selected_destination = isset($_GET['destination']) ? urldecode($_GET['destination']) : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : 0;
$selected_trip = isset($_GET['trip']) ? urldecode($_GET['trip']) : '';
$selected_schedule_id = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;
$booking_success = false;
$booking_error = '';
$booking_reference = '';

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_ticket'])) {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $seat_number = isset($_POST['seat_number']) ? intval($_POST['seat_number']) : 0;
    $booking_date = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : 'regular';
    
    // Validation
    $errors = [];
    if ($bus_id <= 0) {
        $errors[] = "Please select a valid bus";
    }
    if ($seat_number <= 0) {
        $errors[] = "Please select a seat";
    }
    if (empty($booking_date)) {
        $errors[] = "Please select a travel date";
    }
    if (empty($payment_method)) {
        $errors[] = "Please select a payment method";
    }
    
    // Validate payment proof for online payment methods
    $payment_proof_path = null;
    if (($payment_method === 'gcash' || $payment_method === 'paymaya') && 
        (!isset($_FILES[$payment_method . '_payment_proof']) || $_FILES[$payment_method . '_payment_proof']['error'] !== UPLOAD_ERR_OK)) {
        $errors[] = "Please upload payment proof for " . strtoupper($payment_method);
    }
    
    // Validate discount ID proof if discount is selected
    $discount_id_path = null;
    if ($discount_type !== 'regular' && 
        (!isset($_FILES['discount_id_proof']) || $_FILES['discount_id_proof']['error'] !== UPLOAD_ERR_OK)) {
        $errors[] = "Please upload valid ID for " . ucfirst($discount_type) . " discount verification";
    }
    
    if (empty($errors)) {
        try {
            // First, make sure the necessary columns exist
            try {
                // Add discount related columns if they don't exist
                $alter_discount_type = "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) DEFAULT 'regular'";
                $conn->query($alter_discount_type);
                
                $alter_discount_id = "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS discount_id_proof VARCHAR(255) DEFAULT NULL";
                $conn->query($alter_discount_id);
                
                $alter_discount_verified = "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS discount_verified TINYINT(1) DEFAULT 0";
                $conn->query($alter_discount_verified);
                
            } catch (Exception $e) {
                error_log("Error adding discount columns: " . $e->getMessage());
                // Continue with the booking process anyway
            }
            
            // Process payment proof upload if applicable
            if ($payment_method === 'gcash' || $payment_method === 'paymaya') {
                $payment_proof_path = processPaymentProofUpload($payment_method);
                
                if (!$payment_proof_path) {
                    throw new Exception("Failed to upload payment proof. Please try again.");
                }
            }
            
            // Process discount ID upload if applicable
            if ($discount_type !== 'regular') {
                $discount_id_path = processDiscountIDUpload($discount_type);
                
                if (!$discount_id_path) {
                    throw new Exception("Failed to upload discount ID proof. Please try again.");
                }
            }
            
            // Check if seat is already booked
            $check_query = "SELECT id FROM bookings WHERE bus_id = ? AND seat_number = ? AND booking_date = ? AND booking_status = 'confirmed'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("iis", $bus_id, $seat_number, $booking_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $booking_error = "This seat is already booked. Please select another seat.";
            } else {
                // Begin a transaction to ensure all operations succeed or fail together
                $conn->begin_transaction();
                
                // Generate booking reference
                $booking_id_temp = $conn->insert_id + 1; // Estimate the next ID
                $booking_reference = 'BK-' . date('Ymd') . '-' . $booking_id_temp;
                
                // Get trip number for this bus
                $trip_number = null;
                $trip_query = "SELECT trip_number FROM schedules WHERE bus_id = ? LIMIT 1";
                $trip_stmt = $conn->prepare($trip_query);
                $trip_stmt->bind_param("i", $bus_id);
                $trip_stmt->execute();
                $trip_result = $trip_stmt->get_result();
                if ($trip_result && $trip_result->num_rows > 0) {
                    $trip_data = $trip_result->fetch_assoc();
                    $trip_number = $trip_data['trip_number'];
                }
                
                // Set payment status based on payment method
                $payment_status = ($payment_method === 'counter') ? 'pending' : 
                                 (($payment_method === 'gcash' || $payment_method === 'paymaya') ? 'awaiting_verification' : 'pending');
                
                // Set payment proof status
                $payment_proof_status = ($payment_method === 'counter') ? 'not_required' : 
                                      ($payment_proof_path ? 'uploaded' : 'pending');
                
                // Get current timestamp for payment proof upload
                $current_timestamp = date('Y-m-d H:i:s');
                
                // Insert booking with payment information, proof, and discount details
                $insert_query = "INSERT INTO bookings (bus_id, user_id, seat_number, booking_date, booking_status, 
                                created_at, booking_reference, trip_number, payment_method, payment_status, 
                                payment_proof, payment_proof_status, payment_proof_timestamp,
                                discount_type, discount_id_proof, discount_verified) 
                                VALUES (?, ?, ?, ?, 'confirmed', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("iiissssssssss", $bus_id, $user_id, $seat_number, $booking_date, 
                                        $booking_reference, $trip_number, $payment_method, $payment_status, 
                                        $payment_proof_path, $payment_proof_status, $current_timestamp,
                                        $discount_type, $discount_id_path);
                
                if ($insert_stmt->execute()) {
                    $booking_id = $conn->insert_id;
                    
                    // Update the booking reference with actual ID
                    $booking_reference = 'BK-' . date('Ymd') . '-' . $booking_id;
                    $update_query = "UPDATE bookings SET booking_reference = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $booking_reference, $booking_id);
                    
                    if (!$update_stmt->execute()) {
                        error_log("Failed to update booking reference: " . $update_stmt->error);
                    }
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    // Set success flag and redirect to receipt page
                    $booking_success = true;
                    
                    // Redirect to the booking receipt page
                    header("Location: auth/booking_receipt.php?booking_id=" . $booking_id);
                    exit;
                    
                } else {
                    // Rollback in case of failure
                    $conn->rollback();
                    $booking_error = "Error creating booking. Please try again.";
                }
            }
        } catch (Exception $e) {
            // Rollback in case of any exception
            if ($conn->inTransaction()) {
                $conn->rollback();
            }
            $booking_error = "Database error: " . $e->getMessage();
        }
    } else {
        $booking_error = implode(", ", $errors);
    }
}

/**
 * Process payment proof image upload
 * 
 * @param string $payment_method The payment method (gcash or paymaya)
 * @return string|null The path to the uploaded image or null on failure
 */
function processPaymentProofUpload($payment_method) {
    try {
        // Check if file was uploaded
        if (!isset($_FILES[$payment_method . '_payment_proof']) || 
            $_FILES[$payment_method . '_payment_proof']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $file = $_FILES[$payment_method . '_payment_proof'];
        
        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/../../uploads/payment_proofs/';
        
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Validate file type
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || 
            !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            return null;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }
        
        // Generate a unique filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $payment_method . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        // Move the uploaded file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Return the relative path to store in database
            return 'uploads/payment_proofs/' . $unique_filename;
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error processing payment proof upload: " . $e->getMessage());
        return null;
    }
}

/**
 * Process discount ID image upload
 * 
 * @param string $discount_type The discount type (student, senior, pwd)
 * @return string|null The path to the uploaded image or null on failure
 */
function processDiscountIDUpload($discount_type) {
    try {
        // Debug - log the received files
        error_log("Discount ID upload - FILES: " . print_r($_FILES, true));
        
        // Check if file was uploaded
        if (!isset($_FILES['discount_id_proof']) || 
            $_FILES['discount_id_proof']['error'] !== UPLOAD_ERR_OK) {
            error_log("Discount ID file not uploaded or has error: " . 
                      (isset($_FILES['discount_id_proof']) ? $_FILES['discount_id_proof']['error'] : 'Not set'));
            return null;
        }
        
        $file = $_FILES['discount_id_proof'];
        
        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/../../uploads/discount_ids/';
        error_log("Upload directory: " . $upload_dir);
        
        if (!file_exists($upload_dir)) {
            $mkdir_result = mkdir($upload_dir, 0755, true);
            error_log('Created discount IDs directory: ' . ($mkdir_result ? 'success' : 'failed'));
            
            if (!$mkdir_result) {
                error_log('mkdir error: ' . error_get_last()['message']);
                return null;
            }
        }

        if (!is_writable($upload_dir)) {
            error_log('Warning: Discount IDs directory is not writable: ' . $upload_dir);
            // Try to make it writable
            chmod($upload_dir, 0755);
            error_log('After chmod, directory is writable: ' . (is_writable($upload_dir) ? 'yes' : 'no'));
            
            if (!is_writable($upload_dir)) {
                error_log('Directory still not writable after chmod');
                return null;
            }
        }
        
        // Validate file type
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || 
            !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            error_log("Invalid file type: " . (isset($file_info[2]) ? $file_info[2] : 'unknown'));
            return null;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            error_log("File too large: " . $file['size'] . " bytes");
            return null;
        }
        
        // Generate a unique filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $discount_type . '_id_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        // Move the uploaded file
        $move_result = move_uploaded_file($file['tmp_name'], $upload_path);
        error_log("Moving file result: " . ($move_result ? 'success' : 'failed'));
        
        if ($move_result) {
            // Return the relative path to store in database
            $relative_path = 'uploads/discount_ids/' . $unique_filename;
            error_log("File upload successful, returning path: " . $relative_path);
            return $relative_path;
        } else {
            $error = error_get_last();
            error_log("Move error: " . ($error ? $error['message'] : 'Unknown error'));
            error_log("Source file exists: " . (file_exists($file['tmp_name']) ? 'yes' : 'no'));
            error_log("Source file readable: " . (is_readable($file['tmp_name']) ? 'yes' : 'no'));
            error_log("Destination path: " . $upload_path);
            return null;
        }
    } catch (Exception $e) {
        error_log("Error processing discount ID upload: " . $e->getMessage());
        return null;
    }
}

// Fetch all destinations from routes table for dropdowns
$locations = [];
try {
    // Changed to use routes table for origin/destination
    $locations_query = "SELECT DISTINCT origin FROM routes UNION SELECT DISTINCT destination FROM routes ORDER BY origin";
    $locations_result = $conn->query($locations_query);
    
    if ($locations_result) {
        while ($row = $locations_result->fetch_assoc()) {
            $locations[] = $row['origin'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching locations: " . $e->getMessage());
}

// Fetch all buses for display (regardless of route selection)
$all_buses = [];
try {
    // Updated to match your DB schema (using route_name field)
    $all_buses_query = "SELECT b.*, 
                        (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND booking_status = 'confirmed') as active_bookings
                        FROM buses b 
                        ORDER BY b.status, b.id";
    
    $all_buses_result = $conn->query($all_buses_query);
    
    if ($all_buses_result) {
        while ($row = $all_buses_result->fetch_assoc()) {
            // Extract origin and destination from route_name field (format: origin → destination)
            $route_parts = explode(' → ', $row['route_name']);
            $row['origin'] = $route_parts[0] ?? '';
            $row['destination'] = $route_parts[1] ?? '';
            
            $all_buses[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching all buses: " . $e->getMessage());
}

// Fetch available buses based on selected criteria
$available_buses = [];
if (!empty($selected_origin) && !empty($selected_destination)) {
    try {
        // Diagnostic logging
        error_log("Search Parameters:");
        error_log("Origin: $selected_origin");
        error_log("Destination: $selected_destination");
        error_log("Date: $selected_date");
        error_log("Selected Bus ID: $selected_bus_id");

        // Modified query to get all schedules for each bus
        $buses_query = "SELECT 
                b.id as bus_id, 
                b.bus_type, 
                b.seat_capacity, 
                b.plate_number, 
                b.driver_name, 
                b.conductor_name, 
                b.status, 
                b.route_name,
                s.id as schedule_id,
                s.departure_time, 
                s.arrival_time, 
                s.trip_number,
                s.recurring,
                s.date as schedule_date,
                r.fare as fare_amount,
                (SELECT COUNT(*) FROM bookings 
                 WHERE bus_id = b.id 
                 AND DATE(booking_date) = ? 
                 AND booking_status = 'confirmed'
                 AND trip_number = s.trip_number) as booked_seats
            FROM buses b
            LEFT JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
            LEFT JOIN schedules s ON b.id = s.bus_id
            WHERE 
                b.route_name LIKE ? 
                AND b.status = 'Active'
                AND s.status = 'active'
                AND (
                    s.recurring = 1 
                    OR (s.recurring = 0 AND s.date = ?)
                )";
        
        // If a specific bus_id is provided, filter by it
        if ($selected_bus_id > 0) {
            $buses_query .= " AND b.id = ?";
        }
        
        $buses_query .= " ORDER BY b.id, s.departure_time";
        
        // Prepare and execute the statement
        $buses_stmt = $conn->prepare($buses_query);
        
        // Create route pattern
        $route_pattern = '%' . $selected_origin . ' → ' . $selected_destination . '%';
        
        // Bind parameters
        if ($selected_bus_id > 0) {
            $buses_stmt->bind_param("sssi", $selected_date, $route_pattern, $selected_date, $selected_bus_id);
        } else {
            $buses_stmt->bind_param("sss", $selected_date, $route_pattern, $selected_date);
        }
        
        // Execute and check for errors
        if (!$buses_stmt->execute()) {
            error_log("Query execution error: " . $buses_stmt->error);
            throw new Exception("Failed to execute bus search query");
        }
        
        // Get results
        $buses_result = $buses_stmt->get_result();
        
        // Fetch all bus and schedule combinations
        while ($row = $buses_result->fetch_assoc()) {
            // Format times
            $row['departure_time'] = date('h:i A', strtotime($row['departure_time']));
            $row['arrival_time'] = date('h:i A', strtotime($row['arrival_time']));
            
            // Calculate available seats
            $row['available_seats'] = $row['seat_capacity'] - $row['booked_seats'];
            
            // Extract origin and destination from route_name
            $route_parts = explode(' → ', $row['route_name']);
            $row['origin'] = $route_parts[0] ?? $selected_origin;
            $row['destination'] = $route_parts[1] ?? $selected_destination;
            
            // For display purposes, use bus_id as the id
            $row['id'] = $row['bus_id'];
            
            // If trip_number is empty, set a default
            if (empty($row['trip_number'])) {
                $row['trip_number'] = 'Trip';
            }
            
            $available_buses[] = $row;
        }
        
        // Diagnostic logging for found buses
        error_log("Number of bus trips found: " . count($available_buses));
        
    } catch (Exception $e) {
        error_log("Error fetching buses: " . $e->getMessage());
    }
}

// Fetch user data (optional, for the form)
$user_data = null;
try {
    $user_query = "SELECT * FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result && $user_result->num_rows === 1) {
        $user_data = $user_result->fetch_assoc();
    }
} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
}

// Fetch booked seats for a specific bus and date
function getBookedSeats($conn, $busId, $date) {
    $booked_seats = [];
    
    if ($busId && $date) {
        try {
            $query = "SELECT seat_number FROM bookings 
                      WHERE bus_id = ? AND DATE(booking_date) = ? AND booking_status = 'confirmed'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $busId, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $booked_seats[] = (int)$row['seat_number'];
            }
        } catch (Exception $e) {
            error_log("Error fetching booked seats: " . $e->getMessage());
        }
    }
    
    return $booked_seats;
}

// If we have a bus_id from post, prepare booked seats for the current bus
$current_bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
$booked_seats = [];
if ($current_bus_id > 0) {
    $booked_seats = getBookedSeats($conn, $current_bus_id, $selected_date);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Ticket - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/navfot.css">
    <style>
        
        .seat {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: bold;
            color: white;
            transition: all 0.3s;
            margin: 5px;
            position: relative;
            border: 2px solid transparent;
        }
        
        .seat-map-container {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.1);
        }
        
        .seat.available {
            background-color: #28a745;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .seat.available:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            border-color: #fff;
        }
        
        .seat.booked {
            background-color: #dc3545;
            cursor: not-allowed;
            opacity: 0.8;
        }
        
        .seat.selected {
            background-color: #007bff;
            transform: scale(1.1);
            box-shadow: 0 0 10px rgba(0,123,255,0.6);
            z-index: 2;
            border-color: #fff;
        }
        
        .seat-row {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
            gap: 10px;
            align-items: center;
        }
        
        .aisle {
            width: 20px;
            height: 40px;
        }
        
        .driver-area {
            max-width: 180px;
            margin: 0 auto 20px;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 8px;
            border: 1px dashed #adb5bd;
            font-weight: bold;
        }
        
        .seat-status-card {
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
            border-radius: 10px;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }
        
        .seat-counter {
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: all 0.2s ease;
        }
        
        .bus-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: all 0.3s;
            cursor: pointer;
            background-color: #fff;
        }
        
        .bus-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .bus-card.selected {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.3);
        }
        
        .ticket-summary-card {
            position: sticky;
            top: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .booking-steps .step {
            padding: 15px;
            border-bottom: 3px solid #e9ecef;
            margin-bottom: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            transition: all 0.3s;
        }
        
        .booking-steps .step.active {
            border-bottom-color: #007bff;
            background-color: #e7f1ff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .booking-steps .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e9ecef;
            color: #495057;
            margin-right: 10px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .booking-steps .step.active .step-number {
            background-color: #007bff;
            color: white;
        }
        
        .seat-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 10px;
            background-color: white;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .front-back-indicator {
            background-color: #6c757d;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            margin: 10px 0;
            display: inline-block;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .seat-row-label {
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #e9ecef;
            border-radius: 50%;
            font-weight: bold;
            color: #495057;
        }
        
        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse-animation {
            animation: pulse 1s infinite;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            border-radius: 8px 8px 0 0;
            padding: 10px 20px;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: #007bff;
            background-color: #f8f9fa;
        }
        
        .badge {
            font-weight: 500;
            padding: 5px 10px;
        }
        
        /* Payment Method Styles */
        .payment-methods {
            margin-top: 20px;
        }
        
        .payment-method-option {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .payment-method-option:hover {
            border-color: #adb5bd;
            background-color: #f8f9fa;
        }
        
        .payment-method-option.selected {
            border-color: #007bff;
            background-color: #e7f1ff;
            box-shadow: 0 0 10px rgba(0,123,255,0.2);
        }
        
        .payment-method-option .payment-radio {
            position: absolute;
            top: 20px;
            right: 20px;
        }
        
        .payment-method-logo {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #fff;
            border-radius: 8px;
            margin-right: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .payment-method-logo i {
            font-size: 28px;
            color: #007bff;
        }
        
        .payment-method-info {
            flex: 1;
        }
        
        .payment-instructions {
            font-size: 14px;
            color: #6c757d;
            margin-top: 10px;
            display: none;
        }
        
        .payment-method-option.selected .payment-instructions {
            display: block;
        }

        .discount-options {
            border-left: 4px solid #0dcaf0;
            padding-left: 15px;
        }

        .fare-updated {
            animation: highlight 2s;
        }

        @keyframes highlight {
            0% { background-color: #fff; }
            15% { background-color: rgba(25, 135, 84, 0.2); }
            100% { background-color: #fff; }
        }

        #id-upload-section {
            transition: all 0.3s ease;
        }

        .form-check-input.discount-option:checked + .form-check-label {
            font-weight: bold;
            color: #0d6efd;
        }
    </style>
</head>
<body>
     <!-- Navigation Bar -->
     <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>Ceres Bus for ISAT-U Commuters
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="booking.php">Book Ticket</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-ticket-alt me-2"></i>Book Your Ticket</h2>
                
                <?php if ($booking_success): ?>
                <!-- Booking Success Message -->
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Booking Successful!</h4>
                    <p>Your ticket has been booked successfully. Your booking reference is: <strong><?php echo $booking_reference; ?></strong></p>
                    <hr>
                    <p class="mb-0">You can view your booking details in <a href="mybookings.php" class="alert-link">My Bookings</a> page.</p>
                </div>
                <?php elseif (!empty($booking_error)): ?>
                <!-- Booking Error Message -->
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $booking_error; ?>
                </div>
                <?php endif; ?>
                
                <!-- Booking Options Tabs -->
                <ul class="nav nav-tabs mb-4" id="bookingTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="book-ticket-tab" data-bs-toggle="tab" data-bs-target="#book-ticket" type="button" role="tab" aria-controls="book-ticket" aria-selected="true">
                            <i class="fas fa-ticket-alt me-2"></i>Book a Ticket
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="view-fleet-tab" data-bs-toggle="tab" data-bs-target="#view-fleet" type="button" role="tab" aria-controls="view-fleet" aria-selected="false">
                            <i class="fas fa-bus-alt me-2"></i>View Bus Fleet
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="bookingTabsContent">
                    <!-- Book Ticket Tab Content -->
                    <div class="tab-pane fade show active" id="book-ticket" role="tabpanel" aria-labelledby="book-ticket-tab">
                        <!-- Booking Steps -->
                        <div class="card mb-4">
                            <div class="card-body booking-steps">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="step active" id="step1">
                                            <span class="step-number">1</span>
                                            <span class="step-text">Select Route & Date</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="step" id="step2">
                                            <span class="step-number">2</span>
                                            <span class="step-text">Choose Bus & Schedule</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="step" id="step3">
                                            <span class="step-number">3</span>
                                            <span class="step-text">Select Seat</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="step" id="step4">
                                            <span class="step-number">4</span>
                                            <span class="step-text">Payment Method</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <!-- Route Selection (Step 1) -->
                                <div class="card mb-4" id="route-selection">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-route me-2"></i>Select Your Route</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="booking.php" method="GET" id="routeForm">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="origin" class="form-label">Origin (From)*</label>
                                                        <select class="form-select" id="origin" name="origin" required>
                                                            <option value="" disabled <?php echo empty($selected_origin) ? 'selected' : ''; ?>>Select Origin</option>
                                                            <?php foreach ($locations as $location): ?>
                                                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $selected_origin === $location ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($location); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="destination" class="form-label">Destination (To)*</label>
                                                        <select class="form-select" id="destination" name="destination" required>
                                                            <option value="" disabled <?php echo empty($selected_destination) ? 'selected' : ''; ?>>Select Destination</option>
                                                            <?php foreach ($locations as $location): ?>
                                                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $selected_destination === $location ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($location); ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="date" class="form-label">Travel Date*</label>
                                                        <input type="date" class="form-control" id="date" name="date" 
                                                            value="<?php echo $selected_date; ?>" 
                                                            min="<?php echo date('Y-m-d'); ?>" 
                                                            max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" 
                                                            required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-center">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-search me-2"></i>Search Buses
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <?php if (!empty($selected_origin) && !empty($selected_destination)): ?>
                                <!-- Bus Selection (Step 2) -->
                                <div class="card mb-4" id="bus-selection">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-bus me-2"></i>Available Buses</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($available_buses) > 0): ?>
                                        <div class="bus-list">
                                            <?php foreach ($available_buses as $index => $bus): ?>
                                            <div class="bus-card p-3" 
                                                data-bus-id="<?php echo $bus['id']; ?>" 
                                                data-schedule-id="<?php echo $bus['schedule_id']; ?>"
                                                data-fare="<?php echo $bus['fare_amount']; ?>" 
                                                data-type="<?php echo $bus['bus_type']; ?>" 
                                                data-capacity="<?php echo $bus['seat_capacity']; ?>" 
                                                data-departure="<?php echo $bus['departure_time']; ?>" 
                                                data-arrival="<?php echo $bus['arrival_time']; ?>"
                                                data-booked="<?php echo $bus['booked_seats']; ?>"
                                                data-available="<?php echo $bus['available_seats']; ?>"
                                                data-trip-number="<?php echo $bus['trip_number']; ?>">
                                                <div class="row align-items-center">
                                                    <div class="col-md-1 text-center">
                                                        <div class="bus-icon">
                                                            <i class="fas fa-bus fs-3 text-primary"></i>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <h5 class="mb-1"><?php echo htmlspecialchars($bus['origin']); ?> to <?php echo htmlspecialchars($bus['destination']); ?></h5>
                                                        <p class="mb-0 text-muted">
                                                            <small>
                                                                <i class="fas fa-clock me-1"></i><?php echo $bus['departure_time']; ?> - <?php echo $bus['arrival_time']; ?>
                                                            </small>
                                                        </p>
                                                        <?php if (!empty($bus['trip_number'])): ?>
                                                        <span class="badge bg-info mt-1">
                                                            <i class="fas fa-route me-1"></i><?php echo htmlspecialchars($bus['trip_number']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <p class="mb-0">
                                                            <?php if ($bus['bus_type'] === 'Aircondition'): ?>
                                                            <span class="badge bg-info text-dark">
                                                                <i class="fas fa-snowflake me-1"></i> Aircon
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="badge bg-secondary">
                                                                <i class="fas fa-bus me-1"></i> Regular
                                                            </span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <p class="mb-0 text-muted">
                                                            <small>
                                                                <i class="fas fa-chair me-1"></i><?php echo $bus['seat_capacity']; ?> Seats
                                                            </small>
                                                        </p>
                                                        <p class="mb-0 text-muted">
                                                            <small>
                                                                <i class="fas fa-id-card me-1"></i>Bus #<?php echo htmlspecialchars($bus['plate_number']); ?>
                                                            </small>
                                                        </p>
                                                    </div>
                                                    <div class="col-md-2 text-center">
                                                        <!-- Seat availability information -->
                                                        <div class="d-flex flex-column">
                                                            <span class="badge bg-success mb-1">
                                                                <i class="fas fa-check-circle me-1"></i>
                                                                <?php echo $bus['available_seats']; ?> Available
                                                            </span>
                                                            <span class="badge bg-danger">
                                                                <i class="fas fa-times-circle me-1"></i>
                                                                <?php echo $bus['booked_seats']; ?> Booked
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2 text-center">
                                                        <h5 class="mb-0 text-primary">₱<?php echo number_format($bus['fare_amount'], 2); ?></h5>
                                                        <small class="text-muted">per person</small>
                                                    </div>
                                                    <div class="col-md-1 text-end">
                                                        <button type="button" class="btn btn-outline-primary btn-sm select-bus">
                                                            <i class="fas fa-check me-1"></i>Select
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <!-- Seat Availability Progress Bar -->
                                                <div class="mt-2">
                                                    <?php 
                                                    $availabilityPercentage = ($bus['available_seats'] / $bus['seat_capacity']) * 100;
                                                    $progressClass = $availabilityPercentage > 66 ? 'bg-success' : ($availabilityPercentage > 33 ? 'bg-warning' : 'bg-danger');
                                                    ?>
                                                    <div class="progress" style="height: 8px;" title="Seat Availability">
                                                        <div class="progress-bar <?php echo $progressClass; ?>" role="progressbar" 
                                                            style="width: <?php echo $availabilityPercentage; ?>%"
                                                            aria-valuenow="<?php echo $availabilityPercentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <div class="d-flex justify-content-between mt-1">
                                                        <small class="text-muted">Fully Booked</small>
                                                        <small class="text-muted">Seat Availability</small>
                                                        <small class="text-muted">All Available</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="alert alert-info" role="alert">
                                            <i class="fas fa-info-circle me-2"></i>No buses available for the selected route and date. Please try a different route or date.
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                
                                <!-- Seat Selection (Step 3) -->
                                <div class="card mb-4" id="seat-selection" style="display: none;">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-chair me-2"></i>Select Your Seat</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <div class="seat-legend">
                                                    <div class="legend-item">
                                                        <div class="seat available" style="width: 25px; height: 25px;"></div>
                                                        <span>Available</span>
                                                    </div>
                                                    <div class="legend-item">
                                                        <div class="seat booked" style="width: 25px; height: 25px;"></div>
                                                        <span>Booked</span>
                                                    </div>
                                                    <div class="legend-item">
                                                        <div class="seat selected" style="width: 25px; height: 25px;"></div>
                                                        <span>Your Selection</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mb-4">
                                            <div class="driver-area">
                                                <i class="fas fa-steering-wheel me-1"></i> Driver Area
                                            </div>
                                            <div class="front-back-indicator">
                                                <i class="fas fa-arrow-up me-1"></i> Front of Bus
                                            </div>
                                        </div>
                                        
                                        <div class="seat-map-container">
                                            <div id="seatMapContainer" class="d-flex flex-column align-items-center justify-content-center">
                                                <!-- Seat map will be dynamically loaded here -->
                                                <div class="spinner-border text-primary mb-3" role="status">
                                                    <span class="visually-hidden">Loading seats...</span>
                                                </div>
                                                <p>Loading seat map...</p>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mb-2">
                                            <div class="front-back-indicator">
                                                <i class="fas fa-arrow-down me-1"></i> Back of Bus
                                            </div>
                                        </div>
                                        
                                        <div class="seat-status-card p-3 mb-4">
                                            <div class="row align-items-center text-center">
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center justify-content-center">
                                                        <div class="seat-counter bg-success text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                            <span id="availableSeatCount">0</span>
                                                        </div>
                                                        <div>
                                                            <span class="d-block fw-bold">Available Seats</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center justify-content-center">
                                                        <div class="seat-counter bg-danger text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                            <span id="bookedSeatCount">0</span>
                                                        </div>
                                                        <div>
                                                            <span class="d-block fw-bold">Booked Seats</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="d-flex align-items-center justify-content-center">
                                                        <div class="seat-counter bg-primary text-white rounded-circle p-2 me-2" style="width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                                            <span id="totalSeatCount">0</span>
                                                        </div>
                                                        <div>
                                                            <span class="d-block fw-bold">Total Seats</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>Click on an available seat to select it. Your selected seat will be highlighted in blue.
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Methods Selection (Step 4) -->
                                <div class="card mb-4" id="payment-selection" style="display: none;">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Select Payment Method</h5>
                                    </div>
                                    <div class="card-body">
                                        <!-- Discount Selection Section - NEW -->
                                        <div class="card mb-4">
                                            <div class="card-header bg-info text-white">
                                                <h5 class="mb-0"><i class="fas fa-tag me-2"></i>Discount Options</h5>
                                            </div>
                                            <div class="card-body">
                                                <div class="alert alert-info mb-3">
                                                    <i class="fas fa-info-circle me-2"></i>If eligible, you can select a discount category. Valid ID is required for verification.
                                                </div>
                                                
                                                <div class="discount-options mb-3">
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input discount-option" type="radio" name="discount_type" id="discount_regular" value="regular" checked>
                                                        <label class="form-check-label" for="discount_regular">
                                                            Regular Fare (No Discount)
                                                        </label>
                                                    </div>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input discount-option" type="radio" name="discount_type" id="discount_student" value="student">
                                                        <label class="form-check-label" for="discount_student">
                                                            Student (20% Off) - Must upload Student ID
                                                        </label>
                                                    </div>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input discount-option" type="radio" name="discount_type" id="discount_senior" value="senior">
                                                        <label class="form-check-label" for="discount_senior">
                                                            Senior Citizen (20% Off) - Must upload Senior Citizen ID
                                                        </label>
                                                    </div>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input discount-option" type="radio" name="discount_type" id="discount_pwd" value="pwd">
                                                        <label class="form-check-label" for="discount_pwd">
                                                            PWD (20% Off) - Must upload PWD ID
                                                        </label>
                                                    </div>
                                                </div>
                                                
                                                <!-- ID Upload Section -->
                                                <div id="id-upload-section" style="display: none;">
                                                    <div class="card card-body bg-light mb-3">
                                                        <h6 class="mb-3"><i class="fas fa-id-card me-2"></i>Upload Valid ID for Verification</h6>
                                                        <div class="mb-3">
                                                            <label for="discount_id_proof" class="form-label">Upload your ID (Required for discount)</label>
                                                            <input class="form-control" type="file" id="discount_id_proof" name="discount_id_proof" accept="image/*">
                                                            <div class="form-text">Valid ID must clearly show your name, photo, and ID type. Max 5MB (JPG, PNG)</div>
                                                        </div>
                                                        <div id="id-preview" class="mt-2 d-none">
                                                            <div class="card">
                                                                <div class="card-body p-2">
                                                                    <div class="d-flex align-items-center">
                                                                        <img src="" alt="ID preview" class="img-thumbnail me-2" style="max-width: 100px; max-height: 100px;">
                                                                        <div>
                                                                            <p class="mb-1 small id-preview-filename">filename.jpg</p>
                                                                            <div class="d-flex">
                                                                                <button type="button" class="btn btn-sm btn-danger remove-id-preview me-2">
                                                                                    <i class="fas fa-times me-1"></i>Remove
                                                                                </button>
                                                                                <button type="button" class="btn btn-sm btn-primary change-id-preview">
                                                                                    <i class="fas fa-exchange-alt me-1"></i>Change
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="alert alert-info mb-4">
                                            <i class="fas fa-info-circle me-2"></i>Please select your preferred payment method below.
                                        </div>
                                        
                                        <div class="payment-methods">
                                            <!-- Over the Counter Payment -->
                                            <div class="payment-method-option d-flex" data-payment="counter">
                                                <div class="payment-method-logo">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </div>
                                                <div class="payment-method-info">
                                                    <h5 class="mb-1">Pay Over the Counter</h5>
                                                    <p class="mb-2 text-muted">Pay directly at the bus terminal before your trip</p>
                                                    <div class="payment-instructions">
                                                        <div class="card card-body bg-light">
                                                            <p class="mb-0"><strong>How it works:</strong></p>
                                                            <ol class="mb-0 ps-3">
                                                                <li>Arrive at the terminal at least 30 minutes before departure</li>
                                                                <li>Present your booking reference at the counter</li>
                                                                <li>Pay the exact amount in cash</li>
                                                                <li>Receive your physical ticket and boarding pass</li>
                                                            </ol>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="payment-radio form-check">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_counter" value="counter">
                                                </div>
                                            </div>
                                            
                                            <!-- GCash Payment with Proof Upload -->
                                            <div class="payment-method-option d-flex" data-payment="gcash">
                                                <div class="payment-method-logo">
                                                    <img src="../assets/GCash_logo.png" alt="GCash Logo" class="img-fluid" style="width: 60px; height: auto;">
                                                    <i class="fas fa-mobile-alt d-none"></i>
                                                </div>
                                                <div class="payment-method-info">
                                                    <h5 class="mb-1">GCash</h5>
                                                    <p class="mb-2 text-muted">Pay instantly using your GCash account</p>
                                                    <div class="payment-instructions">
                                                        <div class="card card-body bg-light">
                                                            <div class="row">
                                                                <div class="col-md-7">
                                                                    <p class="mb-2"><strong>How to pay via GCash:</strong></p>
                                                                    <ol class="mb-3 ps-3">
                                                                        <li>Open your GCash app</li>
                                                                        <li>Tap on "Scan QR Code"</li>
                                                                        <li>Scan the QR code shown here</li>
                                                                        <li>Enter the exact amount: ₱<span class="fare-amount">0.00</span></li>
                                                                        <li>Confirm the payment</li>
                                                                        <li>Take a screenshot of your payment receipt</li>
                                                                        <li>Upload the screenshot below</li>
                                                                        <li>Make sure the admin has verified your ticket on the My Bookings page before presenting it to the staff</li>
                                                                    </ol>
                                                                    <div class="alert alert-info small mb-2">
                                                                        <i class="fas fa-info-circle me-1"></i> Payment will be verified by our staff after you upload the receipt.
                                                                    </div>
                                                                    
                                                                    <!-- Payment Proof Upload Section -->
                                                                    <div class="payment-proof-upload mt-3">
                                                                        <label for="gcash_payment_proof" class="form-label"><strong>Upload Payment Screenshot:</strong></label>
                                                                        <input class="form-control form-control-sm" id="gcash_payment_proof" name="gcash_payment_proof" type="file" accept="image/*">
                                                                        <div class="form-text">Supported formats: JPG, PNG (Max 5MB)</div>
                                                                        <div class="payment-preview mt-2 d-none">
                                                                            <div class="card">
                                                                                <div class="card-body p-2">
                                                                                    <div class="d-flex align-items-center">
                                                                                        <img src="" alt="Payment preview" class="img-thumbnail me-2" style="max-width: 100px; max-height: 100px;">
                                                                                        <div>
                                                                                            <p class="mb-1 small preview-filename">filename.jpg</p>
                                                                                            <div class="d-flex">
                                                                                                <button type="button" class="btn btn-sm btn-danger remove-preview me-2">
                                                                                                    <i class="fas fa-times me-1"></i>Remove
                                                                                                </button>
                                                                                                <button type="button" class="btn btn-sm btn-primary change-preview">
                                                                                                    <i class="fas fa-exchange-alt me-1"></i>Change
                                                                                                </button>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-5 text-center">
                                                                    <div class="qr-code-container p-2 bg-white border rounded mb-2">
                                                                        <img src="../assets/QRgcash.jpg" alt="GCash QR Code" class="img-fluid mb-2" style="max-width: 150px;">
                                                                        <!-- Fallback if image unavailable -->
                                                                        <div class="qr-fallback d-none border border-primary p-4 rounded bg-light text-center mb-2" style="width: 150px; height: 150px; margin: 0 auto;">
                                                                            <i class="fas fa-qrcode fa-5x text-primary mb-2"></i>
                                                                            <p class="small mb-0">GCash QR Code</p>
                                                                        </div>
                                                                    </div>
                                                                    <p class="small text-muted mb-0">ISAT-U Ceres Bus Ticketing</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="payment-radio form-check">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_gcash" value="gcash">
                                                </div>
                                            </div>

                                            <!-- PayMaya Payment with Proof Upload -->
                                            <div class="payment-method-option d-flex" data-payment="paymaya">
                                                <div class="payment-method-logo">
                                                    <img src="../assets/paymaya_icon.jpeg" alt="PayMaya Logo" class="img-fluid" style="width: 60px; height: auto;">
                                                    <!-- Fallback icon if image is not available -->
                                                    <i class="fas fa-credit-card d-none"></i>
                                                </div>
                                                <div class="payment-method-info">
                                                    <h5 class="mb-1">PayMaya</h5>
                                                    <p class="mb-2 text-muted">Secure online payment using PayMaya</p>
                                                    <div class="payment-instructions">
                                                        <div class="card card-body bg-light">
                                                            <div class="row">
                                                                <div class="col-md-7">
                                                                    <p class="mb-2"><strong>How to pay via PayMaya:</strong></p>
                                                                    <ol class="mb-3 ps-3">
                                                                        <li>Open your PayMaya app</li>
                                                                        <li>Select "Scan to Pay"</li>
                                                                        <li>Scan the QR code shown here</li>
                                                                        <li>Enter the exact amount: ₱<span class="fare-amount">0.00</span></li>
                                                                        <li>Complete the payment</li>
                                                                        <li>Take a screenshot of your payment confirmation</li>
                                                                        <li>Upload the screenshot below</li>
                                                                        <li>Make sure the admin has verified your ticket on the My Bookings page before presenting it to the staff</li>
                                                                    </ol>
                                                                    <div class="alert alert-info small mb-2">
                                                                        <i class="fas fa-info-circle me-1"></i> Your booking will be confirmed after our staff verifies your payment.
                                                                    </div>
                                                                    
                                                                    <!-- Payment Proof Upload Section -->
                                                                    <div class="payment-proof-upload mt-3">
                                                                        <label for="paymaya_payment_proof" class="form-label"><strong>Upload Payment Screenshot:</strong></label>
                                                                        <input class="form-control form-control-sm" id="paymaya_payment_proof" name="paymaya_payment_proof" type="file" accept="image/*">
                                                                        <div class="form-text">Supported formats: JPG, PNG (Max 5MB)</div>
                                                                        <div class="payment-preview mt-2 d-none">
                                                                            <div class="card">
                                                                                <div class="card-body p-2">
                                                                                    <div class="d-flex align-items-center">
                                                                                        <img src="" alt="Payment preview" class="img-thumbnail me-2" style="max-width: 100px; max-height: 100px;">
                                                                                        <div>
                                                                                            <p class="mb-1 small preview-filename">filename.jpg</p>
                                                                                            <div class="d-flex">
                                                                                                <button type="button" class="btn btn-sm btn-danger remove-preview me-2">
                                                                                                    <i class="fas fa-times me-1"></i>Remove
                                                                                                </button>
                                                                                                <button type="button" class="btn btn-sm btn-primary change-preview">
                                                                                                    <i class="fas fa-exchange-alt me-1"></i>Change
                                                                                                </button>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-5 text-center">
                                                                    <div class="qr-code-container p-2 bg-white border rounded mb-2">
                                                                        <img src="../assets/QRgcash.jpg" alt="PayMaya QR Code" class="img-fluid mb-2" style="max-width: 150px;">
                                                                        <!-- Fallback if image unavailable -->
                                                                        <div class="qr-fallback d-none border border-primary p-4 rounded bg-light text-center mb-2" style="width: 150px; height: 150px; margin: 0 auto;">
                                                                            <i class="fas fa-qrcode fa-5x text-primary mb-2"></i>
                                                                            <p class="small mb-0">PayMaya QR Code</p>
                                                                        </div>
                                                                    </div>
                                                                    <p class="small text-muted mb-0">ISAT-U Ceres Bus Ticketing</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="payment-radio form-check">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_paymaya" value="paymaya">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between mt-4">
                                            <button type="button" class="btn btn-secondary" id="backToSeatBtn">
                                                <i class="fas fa-arrow-left me-2"></i>Back to Seat Selection
                                            </button>
                                            <button type="button" class="btn btn-success" id="proceedToConfirmBtn" disabled>
                                                <i class="fas fa-check-circle me-2"></i>Proceed to Confirm
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <!-- Booking Summary -->
                                <div class="card ticket-summary-card">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Booking Summary</h5>
                                    </div>
                                    <div class="card-body">
                                        <form action="booking.php" method="POST" id="bookingForm" enctype="multipart/form-data">
                                            <input type="hidden" name="bus_id" id="summary_bus_id" value="">
                                            <input type="hidden" name="seat_number" id="summary_seat_number" value="">
                                            <input type="hidden" name="booking_date" id="summary_booking_date" value="<?php echo $selected_date; ?>">
                                            <input type="hidden" name="discount_type" id="summary_discount_type" value="regular">
                                            <input type="hidden" name="payment_method" id="summary_payment_method" value="">
                                            <input type="hidden" name="book_ticket" value="1">
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Passenger Name</label>
                                                <div class="form-control bg-light"><?php echo htmlspecialchars($user_name); ?></div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Route</label>
                                                <div class="form-control bg-light" id="summary_route" aria-live="polite">
                                                    <?php if (!empty($selected_origin) && !empty($selected_destination)): ?>
                                                    <?php echo htmlspecialchars($selected_origin); ?> to <?php echo htmlspecialchars($selected_destination); ?>
                                                    <?php else: ?>
                                                    Not selected
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Seat Availability Information -->
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Seat Availability</label>
                                                <div id="summary_seat_info" class="mb-2">
                                                    <span class="badge bg-secondary">Not selected</span>
                                                </div>
                                                <div id="seat-availability-visual">
                                                    <div class="progress mb-2" style="height: 10px;">
                                                        <div class="progress-bar bg-secondary" role="progressbar" 
                                                            style="width: 100%"
                                                            aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <div class="small text-center text-muted">
                                                        Select a bus to see availability
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Travel Date</label>
                                                <div class="form-control bg-light" id="summary_date" aria-live="polite">
                                                    <?php echo date('F d, Y', strtotime($selected_date)); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Bus Type</label>
                                                <div class="form-control bg-light" id="summary_bus_type" aria-live="polite">Not selected</div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Trip Number</label>
                                                <div class="form-control bg-light" id="summary_trip_number" aria-live="polite">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Travel Time</label>
                                                <div class="form-control bg-light" id="summary_departure" aria-live="polite">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Seat Number</label>
                                                <div class="form-control bg-light" id="summary_seat" aria-live="polite">Not selected</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Fare Amount</label>
                                                <div class="form-control bg-light" id="summary_fare" aria-live="polite">₱0.00</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Payment Method</label>
                                                <div class="form-control bg-light" id="summary_payment_display" aria-live="polite">Not selected</div>
                                            </div>
                                            
                                            <!-- Discount ID Proof Upload -->
                                            <div class="mb-3" id="discount-proof-section" style="display: none;">
                                                <label class="form-label fw-bold">Discount ID Proof</label>
                                                <input type="file" name="discount_id_proof" id="discount_id_proof" class="form-control" accept="image/*,.pdf">
                                                <small class="text-muted">Upload your student/senior/PWD ID (JPG, PNG, or PDF, max 5MB)</small>
                                                <div id="id-preview" class="mt-2 d-none">
                                                    <div class="card">
                                                        <div class="card-body p-2">
                                                            <div class="d-flex align-items-center">
                                                                <div class="me-3">
                                                                    <img id="id-preview-image" src="#" alt="ID Preview" class="img-thumbnail" style="max-height: 60px; display: none;">
                                                                    <div id="id-preview-pdf" class="bg-light p-2 rounded" style="display: none;">
                                                                        <i class="fas fa-file-pdf text-danger fa-2x"></i>
                                                                    </div>
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <div class="id-preview-filename small text-truncate"></div>
                                                                    <div class="d-flex gap-2 mt-2">
                                                                        <button type="button" class="btn btn-sm btn-outline-secondary change-id-preview">
                                                                            <i class="fas fa-sync me-1"></i> Change
                                                                        </button>
                                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-id-preview">
                                                                            <i class="fas fa-trash me-1"></i> Remove
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Payment Proof Upload (will be shown based on payment method) -->
                                            <div class="mb-3" id="payment-proof-section" style="display: none;">
                                                <label class="form-label fw-bold">Payment Proof</label>
                                                <input type="file" name="payment_proof" id="payment_proof" class="form-control" accept="image/*">
                                                <small class="text-muted">Upload screenshot of your payment (JPG or PNG, max 5MB)</small>
                                                <div id="payment-preview" class="mt-2 d-none">
                                                    <div class="card">
                                                        <div class="card-body p-2">
                                                            <div class="d-flex align-items-center">
                                                                <div class="me-3">
                                                                    <img id="payment-preview-image" src="#" alt="Payment Preview" class="img-thumbnail" style="max-height: 60px;">
                                                                </div>
                                                                <div class="flex-grow-1">
                                                                    <div class="payment-preview-filename small text-truncate"></div>
                                                                    <div class="d-flex gap-2 mt-2">
                                                                        <button type="button" class="btn btn-sm btn-outline-secondary change-payment-preview">
                                                                            <i class="fas fa-sync me-1"></i> Change
                                                                        </button>
                                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-payment-preview">
                                                                            <i class="fas fa-trash me-1"></i> Remove
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Error Message Container -->
                                            <div id="booking-errors" class="alert alert-danger d-none mb-3"></div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-success btn-lg" id="confirmBookingBtn" disabled>
                                                    <span id="submit-text"><i class="fas fa-ticket-alt me-2"></i>Confirm Booking</span>
                                                    <span id="submit-spinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- View Bus Fleet Tab Content -->
                    <div class="tab-pane fade" id="view-fleet" role="tabpanel" aria-labelledby="view-fleet-tab">
                        <div class="fleet-section">
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>Viewing all buses in our fleet. Click on a row to see detailed information about the bus.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <!-- Buses Table -->
                                    <div class="card">
                                        <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0"><i class="fas fa-bus-alt me-2"></i>Registered Buses</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if (count($all_buses) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover bus-info-table">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Type</th>
                                                            <th>Plate Number</th>
                                                            <th>Origin</th>
                                                            <th>Destination</th>
                                                            <th>Capacity</th>
                                                            <th>Driver</th>
                                                            <th>Conductor</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($all_buses as $bus): ?>
                                                        <tr class="bus-row" data-bs-toggle="collapse" data-bs-target="#busDetails<?php echo $bus['id']; ?>" aria-expanded="false" aria-controls="busDetails<?php echo $bus['id']; ?>">
                                                            <td><?php echo $bus['id']; ?></td>
                                                            <td>
                                                                <span class="badge <?php echo $bus['bus_type'] === 'Aircondition' ? 'bg-info text-dark' : 'bg-secondary'; ?>">
                                                                    <?php echo $bus['bus_type'] === 'Aircondition' ? '<i class="fas fa-snowflake me-1"></i> Aircon' : '<i class="fas fa-fan me-1"></i> Regular'; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($bus['plate_number']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['origin']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['destination']); ?></td>
                                                            <td><?php echo $bus['seat_capacity']; ?> seats</td>
                                                            <td><?php echo htmlspecialchars($bus['driver_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($bus['conductor_name']); ?></td>
                                                            <td>
                                                                <span class="status-indicator <?php echo $bus['status'] === 'Active' ? 'status-active' : 'status-maintenance'; ?>"></span>
                                                                <?php echo $bus['status']; ?>
                                                            </td>
                                                            <td>
                                                                <a href="booking.php?origin=<?php echo urlencode($bus['origin']); ?>&destination=<?php echo urlencode($bus['destination']); ?>" class="btn btn-sm btn-primary">
                                                                    <i class="fas fa-ticket-alt me-1"></i>Book
                                                                </a>
                                                            </td>
                                                        </tr>
                                                        <tr class="collapse" id="busDetails<?php echo $bus['id']; ?>">
                                                            <td colspan="10">
                                                                <div class="card card-body bg-light mb-0">
                                                                    <div class="row">
                                                                        <div class="col-md-6">
                                                                            <h6><i class="fas fa-info-circle me-2"></i>Bus Details</h6>
                                                                            <ul class="list-unstyled">
                                                                                <li><strong>Bus ID:</strong> #<?php echo $bus['id']; ?></li>
                                                                                <li><strong>Type:</strong> <?php echo $bus['bus_type']; ?></li>
                                                                                <li><strong>Plate Number:</strong> <?php echo htmlspecialchars($bus['plate_number']); ?></li>
                                                                                <li><strong>Seating Capacity:</strong> <?php echo $bus['seat_capacity']; ?> seats</li>
                                                                                <li><strong>Current Status:</strong> <?php echo $bus['status']; ?></li>
                                                                            </ul>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <h6><i class="fas fa-route me-2"></i>Route & Personnel</h6>
                                                                            <ul class="list-unstyled">
                                                                                <li><strong>Route:</strong> <?php echo htmlspecialchars($bus['origin']); ?> to <?php echo htmlspecialchars($bus['destination']); ?></li>
                                                                                <li><strong>Driver:</strong> <?php echo htmlspecialchars($bus['driver_name']); ?></li>
                                                                                <li><strong>Conductor:</strong> <?php echo htmlspecialchars($bus['conductor_name']); ?></li>
                                                                                <li><strong>Active Bookings:</strong> <?php echo $bus['active_bookings']; ?></li>
                                                                                <li><strong>Added On:</strong> <?php echo isset($bus['created_at']) ? date('M d, Y', strtotime($bus['created_at'])) : 'N/A'; ?></li>
                                                                            </ul>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>No buses have been registered in the system yet.
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="footer mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-md-4 mb-3">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="routes.php" class="text-white">Routes</a></li>
                        <li><a href="schedule.php" class="text-white">Schedule</a></li>
                        <li><a href="booking.php" class="text-white">Book Ticket</a></li>
                        <li><a href="contact.php" class="text-white">Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h5>Contact</h5>
                    <address>
                        <i class="fas fa-map-marker-alt me-2"></i> Ceres Terminal, Iloilo City<br>
                        <i class="fas fa-phone me-2"></i> (033) 337-8888<br>
                        <i class="fas fa-envelope me-2"></i> isatucommuters@ceresbus.com
                    </address>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center">
                <p>&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize variables
        let selectedBusId = null;
        let selectedSeatNumber = null;
        let selectedBusData = null;
        let selectedPaymentMethod = null;
        
        // Bus selection
        document.querySelectorAll('.bus-card').forEach(function(card) {
            card.addEventListener('click', function() {
                const busId = this.getAttribute('data-bus-id');
                const fareAmount = this.getAttribute('data-fare');
                const busType = this.getAttribute('data-type');
                const capacity = this.getAttribute('data-capacity');
                const departure = this.getAttribute('data-departure');
                const arrival = this.getAttribute('data-arrival');
                const bookedSeats = parseInt(this.getAttribute('data-booked') || '0');
                const availableSeats = parseInt(this.getAttribute('data-available') || capacity);
                const tripNumber = this.getAttribute('data-trip-number') || 'Not specified';
                
                // Remove selection from all buses
                document.querySelectorAll('.bus-card').forEach(function(c) {
                    c.classList.remove('selected');
                });
                
                // Select this bus
                this.classList.add('selected');
                selectedBusId = busId;
                
                // Store bus data for summary
                selectedBusData = {
                    id: busId,
                    type: busType === 'Aircondition' ? 'Aircon Bus' : 'Regular Bus',
                    departure: `${departure} - ${arrival}`,
                    capacity: capacity,
                    fare: fareAmount,
                    bookedSeats: bookedSeats,
                    availableSeats: availableSeats,
                    tripNumber: tripNumber
                };
                
                // Update summary
                document.getElementById('summary_bus_id').value = busId;
                document.getElementById('summary_bus_type').textContent = selectedBusData.type;
                document.getElementById('summary_departure').textContent = selectedBusData.departure;
                document.getElementById('summary_fare').textContent = '₱' + parseFloat(fareAmount).toFixed(2);
                
                // Update trip number in summary
                if (document.getElementById('summary_trip_number')) {
                    document.getElementById('summary_trip_number').textContent = tripNumber;
                }
                
                // Add seat availability information to the summary if element exists
                const seatInfoElement = document.getElementById('summary_seat_info');
                if (seatInfoElement) {
                    seatInfoElement.innerHTML = `
                        <span class="badge bg-success me-1">${availableSeats} Available</span>
                        <span class="badge bg-danger">${bookedSeats} Booked</span>
                    `;
                }
                
                // Update seat availability visual if element exists
                const seatVisualElement = document.getElementById('seat-availability-visual');
                if (seatVisualElement) {
                    const availabilityPercentage = (availableSeats / capacity) * 100;
                    const progressClass = availabilityPercentage > 66 ? 'bg-success' : 
                                        (availabilityPercentage > 33 ? 'bg-warning' : 'bg-danger');
                    
                    seatVisualElement.innerHTML = `
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar ${progressClass}" role="progressbar" 
                                style="width: ${availabilityPercentage}%"
                                aria-valuenow="${availabilityPercentage}" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                        <div class="small text-center">
                            ${availableSeats} of ${capacity} seats available (${Math.round(availabilityPercentage)}%)
                        </div>
                    `;
                }
                
                // Show seat selection
                document.getElementById('seat-selection').style.display = 'block';
                
                // Update steps
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step2').classList.add('active');
                
                // Generate seat map
                generateSeatMap(busId, parseInt(capacity));
                
                // Scroll to seat selection
                document.getElementById('seat-selection').scrollIntoView({ behavior: 'smooth' });
            });
        });
        
        // Fetch booked seats and generate seat map
        async function fetchBookedSeats(busId, date) {
            try {
                const response = await fetch(`../../backend/connections/get_booked_seats.php?bus_id=${busId}&date=${date}`);
                
                if (!response.ok) {
                    throw new Error('Failed to fetch booked seats');
                }
                
                const data = await response.json();
                return data.bookedSeats || [];
            } catch (error) {
                console.error('Error fetching booked seats:', error);
                // If there's an error, we'll use PHP-provided booked seats if available
                // or assume no seats are booked
                return <?php echo json_encode($booked_seats); ?> || [];
            }
        }
        
        // Generate the seat map layout
        async function generateSeatMap(busId, totalSeats) {
            const seatMapContainer = document.getElementById('seatMapContainer');
            const date = document.getElementById('date').value;
            
            // Show loading state
            seatMapContainer.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Loading seats...</span>
                    </div>
                    <p>Loading seat map...</p>
                </div>
            `;
            
            try {
                // Get booked seats
                const bookedSeats = await fetchBookedSeats(busId, date);
                
                // Calculate seat counts
                const bookedCount = bookedSeats.length;
                const availableCount = totalSeats - bookedCount;
                
                // Update seat counters
                document.getElementById('bookedSeatCount').textContent = bookedCount;
                document.getElementById('availableSeatCount').textContent = availableCount;
                document.getElementById('totalSeatCount').textContent = totalSeats;
                
                // Clear container
                seatMapContainer.innerHTML = '';
                
                let seatNumber = 1;
                const seatsPerRow = 4; // Default 2-2 layout
                
                // Always reserve 5 seats for the back row
                const backRowSeats = 6;
                const remainingSeats = totalSeats - backRowSeats;
                const normalRows = Math.floor(remainingSeats / seatsPerRow);
                const extraSeats = remainingSeats % seatsPerRow;
                
                // Create normal rows (2-2 layout)
                for (let row = 1; row <= normalRows; row++) {
                    // Create the row element
                    const rowDiv = document.createElement('div');
                    rowDiv.className = 'seat-row';
                    
                    // Add row label (A, B, C, etc.)
                    const rowLabel = document.createElement('div');
                    rowLabel.className = 'seat-row-label';
                    rowLabel.textContent = String.fromCharCode(64 + row); // A, B, C, etc.
                    rowDiv.appendChild(rowLabel);
                    
                    // Add left side seats (2 seats)
                    for (let i = 0; i < seatsPerRow/2; i++) {
                        if (seatNumber <= totalSeats - backRowSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            seatNumber++;
                        }
                    }
                    
                    // Add aisle
                    const aisleDiv = document.createElement('div');
                    aisleDiv.className = 'aisle';
                    rowDiv.appendChild(aisleDiv);
                    
                    // Add right side seats (2 seats)
                    for (let i = 0; i < seatsPerRow/2; i++) {
                        if (seatNumber <= totalSeats - backRowSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            rowDiv.appendChild(seat);
                            seatNumber++;
                        }
                    }
                    
                    seatMapContainer.appendChild(rowDiv);
                }
                
                // Handle extra seats if any (create a partial row before the back row)
                if (extraSeats > 0) {
                    const extraRowDiv = document.createElement('div');
                    extraRowDiv.className = 'seat-row';
                    
                    // Add row label
                    const rowLabel = document.createElement('div');
                    rowLabel.className = 'seat-row-label';
                    rowLabel.textContent = String.fromCharCode(64 + normalRows + 1); // Next letter after normal rows
                    extraRowDiv.appendChild(rowLabel);
                    
                    // Add left side seats
                    const leftSeats = Math.min(extraSeats, 2);
                    for (let i = 0; i < leftSeats; i++) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        extraRowDiv.appendChild(seat);
                        seatNumber++;
                    }
                    
                    // Add aisle
                    const aisleDiv = document.createElement('div');
                    aisleDiv.className = 'aisle';
                    extraRowDiv.appendChild(aisleDiv);
                    
                    // Add right side seats if needed
                    const rightSeats = extraSeats - leftSeats;
                    for (let i = 0; i < rightSeats; i++) {
                        const isBooked = bookedSeats.includes(seatNumber);
                        const seat = createSeatElement(seatNumber, isBooked);
                        extraRowDiv.appendChild(seat);
                        seatNumber++;
                    }
                    
                    seatMapContainer.appendChild(extraRowDiv);
                }
                
                // Create the back row with exactly 5 seats
                if (backRowSeats > 0 && seatNumber <= totalSeats) {
                    const backRowDiv = document.createElement('div');
                    backRowDiv.className = 'seat-row back-row mt-4';
                    
                    // Add row label - use the next letter after the previous rows
                    const backRowLetter = String.fromCharCode(64 + normalRows + (extraSeats > 0 ? 2 : 1));
                    const rowLabel = document.createElement('div');
                    rowLabel.className = 'seat-row-label';
                    rowLabel.textContent = backRowLetter;
                    backRowDiv.appendChild(rowLabel);
                    
                    // Add all 5 back row seats
                    for (let i = 0; i < backRowSeats; i++) {
                        if (seatNumber <= totalSeats) {
                            const isBooked = bookedSeats.includes(seatNumber);
                            const seat = createSeatElement(seatNumber, isBooked);
                            backRowDiv.appendChild(seat);
                            seatNumber++;
                        }
                    }
                    
                    // Add a special class to identify this as the 5-seat back row
                    backRowDiv.classList.add('back-row-five');
                    seatMapContainer.appendChild(backRowDiv);
                }
                
                // Initialize tooltips if Bootstrap is available
                if (typeof bootstrap !== 'undefined') {
                    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                    [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
                }
            } catch (error) {
                console.error('Error generating seat map:', error);
                seatMapContainer.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading seat map. Please try again.
                    </div>
                `;
            }
        }
        
        // Create a seat element
        function createSeatElement(seatNumber, isBooked) {
            const seat = document.createElement('div');
            seat.className = isBooked ? 'seat booked' : 'seat available';
            seat.dataset.seatNumber = seatNumber;
            seat.textContent = seatNumber;
            
            // Add tooltip with more detailed information
            seat.setAttribute('data-bs-toggle', 'tooltip');
            seat.setAttribute('data-bs-placement', 'top');
            
            if (isBooked) {
                seat.setAttribute('title', `Seat ${seatNumber}: Already booked`);
                // Add a small lock icon to indicate booked status (optional)
                if (!seat.querySelector('.seat-icon')) {
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-lock position-absolute';
                    icon.style.fontSize = '10px';
                    icon.style.top = '5px';
                    icon.style.right = '5px';
                    icon.style.color = 'rgba(255,255,255,0.7)';
                    seat.appendChild(icon);
                }
            } else {
                seat.setAttribute('title', `Seat ${seatNumber}: Available - Click to select`);
            }
            
            // Add click handler for available seats
            if (!isBooked) {
                seat.addEventListener('click', function() {
                    // Remove selection from all seats
                    document.querySelectorAll('.seat.selected').forEach(function(s) {
                        s.classList.remove('selected');
                        s.classList.add('available');
                    });
                    
                    // Select this seat
                    this.classList.remove('available');
                    this.classList.add('selected');
                    this.classList.add('pulse-animation');
                    
                    // After animation completes, remove it
                    setTimeout(() => {
                        this.classList.remove('pulse-animation');
                    }, 1000);
                    
                    selectedSeatNumber = seatNumber;
                    
                    // Update summary
                    document.getElementById('summary_seat_number').value = selectedSeatNumber;
                    document.getElementById('summary_seat').textContent = `Seat ${selectedSeatNumber}`;
                    
                    // Show payment methods section
                    document.getElementById('payment-selection').style.display = 'block';
                    
                    // Update steps
                    document.getElementById('step2').classList.remove('active');
                    document.getElementById('step3').classList.remove('active');
                    document.getElementById('step4').classList.add('active');
                    
                    // Show visual confirmation
                    showSeatSelectedAlert(selectedSeatNumber);
                    
                    // Scroll to payment selection
                    document.getElementById('payment-selection').scrollIntoView({ behavior: 'smooth' });
                });
            }
            
            return seat;
        }
        
        // Show seat selection alert
        function showSeatSelectedAlert(seatNumber) {
            // Create the alert
            const seatSelectedAlert = document.createElement('div');
            seatSelectedAlert.className = 'alert alert-success alert-dismissible fade show mt-3';
            seatSelectedAlert.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Seat ${seatNumber} selected!</strong> Please proceed to select your payment method.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Remove any existing alerts
            const existingAlerts = document.querySelectorAll('#seat-selection .alert-success');
            existingAlerts.forEach(alert => alert.remove());
            
            // Add the alert to the seat selection card
            document.querySelector('#seat-selection .card-body').appendChild(seatSelectedAlert);
            
            // Highlight the booking summary
            const summaryCard = document.querySelector('.ticket-summary-card');
            summaryCard.style.boxShadow = '0 0 15px rgba(40, 167, 69, 0.5)';
            
            // Remove highlight after a few seconds
            setTimeout(() => {
                summaryCard.style.boxShadow = '';
            }, 3000);
        }
        
        // Payment method selection
        document.querySelectorAll('.payment-method-option').forEach(function(option) {
            option.addEventListener('click', function() {
                const paymentMethod = this.getAttribute('data-payment');
                
                // Update radio selection
                const radioInput = this.querySelector('input[type="radio"]');
                radioInput.checked = true;
                
                // Remove selection from all payment methods
                document.querySelectorAll('.payment-method-option').forEach(function(opt) {
                    opt.classList.remove('selected');
                });
                
                // Select this payment method
                this.classList.add('selected');
                selectedPaymentMethod = paymentMethod;
                
                // Update summary
                document.getElementById('summary_payment_method').value = paymentMethod;
                
                // Update payment method display in summary
                const paymentDisplay = document.getElementById('summary_payment_display');
                let paymentText = '';
                
                switch(paymentMethod) {
                    case 'counter':
                        paymentText = '<i class="fas fa-money-bill-wave me-2"></i>Pay Over the Counter';
                        break;
                    case 'gcash':
                        paymentText = '<i class="fas fa-mobile-alt me-2"></i>GCash';
                        break;
                    case 'paymaya':
                        paymentText = '<i class="fas fa-credit-card me-2"></i>PayMaya';
                        break;
                    default:
                        paymentText = 'Not selected';
                }
                
                paymentDisplay.innerHTML = paymentText;
                
                // Enable confirm button
                document.getElementById('confirmBookingBtn').disabled = false;
                document.getElementById('proceedToConfirmBtn').disabled = false;
            });
        });

        document.getElementById('date').addEventListener('change', function() {
            const selectedDate = this.value;
            
            // Update the booking summary date display
            const summaryDateElement = document.getElementById('summary_date');
            if (summaryDateElement && selectedDate) {
                // Format the date nicely
                const dateObj = new Date(selectedDate);
                const options = { year: 'numeric', month: 'long', day: 'numeric' };
                const formattedDate = dateObj.toLocaleDateString('en-US', options);
                
                summaryDateElement.textContent = formattedDate;
            }
            
            // Update the hidden input for booking date
            const summaryBookingDate = document.getElementById('summary_booking_date');
            if (summaryBookingDate) {
                summaryBookingDate.value = selectedDate;
            }
        });

        // Initial load: trigger date change event if date already has a value
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('date');
            if (dateInput && dateInput.value) {
                // Trigger the change event to update the summary
                const event = new Event('change');
                dateInput.dispatchEvent(event);
            }
        });
        
        // Back to seat selection button
        document.getElementById('backToSeatBtn').addEventListener('click', function() {
            // Hide payment section
            document.getElementById('payment-selection').style.display = 'none';
            
            // Scroll to seat selection
            document.getElementById('seat-selection').scrollIntoView({ behavior: 'smooth' });
            
            // Update steps
            document.getElementById('step4').classList.remove('active');
            document.getElementById('step3').classList.add('active');
        });
        
        // Proceed to confirmation button
        document.getElementById('proceedToConfirmBtn').addEventListener('click', function() {
            // Scroll to summary card for final confirmation
            document.querySelector('.ticket-summary-card').scrollIntoView({ behavior: 'smooth' });
            
            // Add a pulse animation to the confirm booking button
            const confirmBtn = document.getElementById('confirmBookingBtn');
            confirmBtn.classList.add('pulse-animation');
            
            // Remove animation after a few seconds
            setTimeout(() => {
                confirmBtn.classList.remove('pulse-animation');
            }, 2000);
        });
        
        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            // Check if bus is selected
            if (!selectedBusId) {
                e.preventDefault();
                alert('Please select a bus first');
                document.getElementById('bus-selection').scrollIntoView({ behavior: 'smooth' });
                return;
            }
            
            // Check if seat is selected
            if (!selectedSeatNumber) {
                e.preventDefault();
                alert('Please select a seat');
                document.getElementById('seat-selection').scrollIntoView({ behavior: 'smooth' });
                return;
            }
            
            // Check if payment method is selected
            if (!selectedPaymentMethod) {
                e.preventDefault();
                alert('Please select a payment method');
                document.getElementById('payment-selection').scrollIntoView({ behavior: 'smooth' });
                return;
            }
            
            // Add a loading overlay when submitting
            document.body.insertAdjacentHTML('beforeend', `
                <div id="loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                    background-color: rgba(0,0,0,0.5); z-index: 9999; display: flex; 
                    justify-content: center; align-items: center;">
                    <div class="card p-4 text-center">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Processing booking...</span>
                        </div>
                        <h5>Processing your booking...</h5>
                        <p>Please wait, this may take a few moments.</p>
                    </div>
                </div>
            `);
        });
        
        // Prevent selecting same origin and destination
        document.getElementById('origin').addEventListener('change', function() {
            const destination = document.getElementById('destination');
            const selectedValue = this.value;
            
            // Enable all options
            for (let i = 0; i < destination.options.length; i++) {
                destination.options[i].disabled = false;
            }
            
            // Disable matching option in destination
            for (let i = 0; i < destination.options.length; i++) {
                if (destination.options[i].value === selectedValue) {
                    destination.options[i].disabled = true;
                    
                    // If currently selected option is now disabled, reset selection
                    if (destination.value === selectedValue) {
                        destination.value = '';
                    }
                    break;
                }
            }
        });
        
        // Make bus rows clickable for details
        document.querySelectorAll('.bus-row').forEach(function(row) {
            row.style.cursor = 'pointer';
        });
        
        // Handle tab navigation preservation
        document.addEventListener('DOMContentLoaded', function() {
            // Add CSS styling for booked seats with striped pattern
            const style = document.createElement('style');
            style.textContent = `
                .back-row-five {
                    justify-content: center !important;
                    padding-right: 25px;
                }
                
                .back-row-five .seat {
                    margin-left: 3px;
                    margin-right: 3px;
                }
                
                .seat.booked {
                    background-color: #dc3545;
                    opacity: 0.7;
                    position: relative;
                    overflow: hidden;
                }
                
                .seat.booked::after {
                    content: "";
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: repeating-linear-gradient(
                        45deg,
                        rgba(0, 0, 0, 0.1),
                        rgba(0, 0, 0, 0.1) 5px,
                        rgba(0, 0, 0, 0.2) 5px,
                        rgba(0, 0, 0, 0.2) 10px
                    );
                }
                
                @media (max-width: 768px) {
                    .seat-row {
                        flex-wrap: wrap;
                    }
                    
                    .back-row-five {
                        padding-right: 0;
                    }
                }
            `;
            document.head.appendChild(style);
            
            // Get the active tab from URL if present
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab === 'fleet') {
                // Activate fleet tab
                document.getElementById('view-fleet-tab').click();
            }
            
            // Add tab parameter to form submission
            document.getElementById('routeForm').addEventListener('submit', function() {
                const activeTab = document.querySelector('.nav-link.active').getAttribute('id');
                if (activeTab === 'view-fleet-tab') {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'tab';
                    hiddenInput.value = 'fleet';
                    this.appendChild(hiddenInput);
                }
            });
            
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
            
            // Show booking success notification with animation
            <?php if ($booking_success): ?>
            setTimeout(() => {
                document.querySelector('.alert-success').scrollIntoView({ behavior: 'smooth' });
            }, 300);
            <?php endif; ?>
            
            // Create seat info elements if they don't exist
            const summaryCard = document.querySelector('.ticket-summary-card .card-body');
            if (summaryCard) {
                // Add seat availability info elements if they don't exist
                if (!document.getElementById('summary_seat_info')) {
                    const seatInfoDiv = document.createElement('div');
                    seatInfoDiv.className = 'mb-3';
                    seatInfoDiv.innerHTML = `
                        <label class="form-label fw-bold">Seat Availability</label>
                        <div id="summary_seat_info" class="mb-2">
                            <span class="badge bg-secondary">Not selected</span>
                        </div>
                        <div id="seat-availability-visual">
                            <div class="progress mb-2" style="height: 10px;">
                                <div class="progress-bar bg-secondary" role="progressbar" 
                                    style="width: 100%"
                                    aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                </div>
                            </div>
                            <div class="small text-center text-muted">
                                Select a bus to see seat availability
                            </div>
                        </div>
                    `;
                    
                    // Insert after the route information
                    const routeElement = summaryCard.querySelector('#summary_route').parentNode;
                    routeElement.parentNode.insertBefore(seatInfoDiv, routeElement.nextSibling);
                }
                
                // Make sure payment method display exists
                if (!document.getElementById('summary_payment_display')) {
                    const paymentDisplayDiv = document.createElement('div');
                    paymentDisplayDiv.className = 'mb-3';
                    paymentDisplayDiv.innerHTML = `
                        <label class="form-label fw-bold">Payment Method</label>
                        <div class="form-control bg-light" id="summary_payment_display">Not selected</div>
                    `;
                    
                    // Insert before the confirm button
                    const confirmBtn = summaryCard.querySelector('#confirmBookingBtn').parentNode;
                    confirmBtn.parentNode.insertBefore(paymentDisplayDiv, confirmBtn);
                }
            }
            
            // Add seat selection guide if it doesn't exist
            const seatSelectionCard = document.querySelector('#seat-selection .card-body');
            if (seatSelectionCard && !seatSelectionCard.querySelector('.seat-selection-guide')) {
                const seatMapContainer = document.querySelector('.seat-map-container');
                const guideElement = document.createElement('div');
                guideElement.className = 'alert alert-info mb-3 seat-selection-guide';
                guideElement.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-info-circle fa-2x text-info"></i>
                        </div>
                        <div>
                            <h6 class="alert-heading mb-1">Seat Selection Guide</h6>
                            <p class="mb-0 small">The seats shown in <span class="text-success fw-bold">green</span> are available for booking, while the seats in <span class="text-danger fw-bold">red</span> are already booked. The back row has 6 seats. Please select one seat for your journey.</p>
                        </div>
                    </div>
                `;
                seatSelectionCard.insertBefore(guideElement, seatMapContainer);
            }
            
            // Add payment method explanation if it doesn't exist
            const paymentSelectionCard = document.querySelector('#payment-selection .card-body');
            if (paymentSelectionCard && !paymentSelectionCard.querySelector('.payment-selection-guide')) {
                const paymentMethods = document.querySelector('.payment-methods');
                if (paymentMethods) {
                    const guideElement = document.createElement('div');
                    guideElement.className = 'alert alert-info mb-3 payment-selection-guide';
                    guideElement.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-info-circle fa-2x text-info"></i>
                            </div>
                            <div>
                                <h6 class="alert-heading mb-1">Payment Method Guide</h6>
                                <p class="mb-0 small">Select your preferred payment method. Over-the-counter lets you pay at the terminal before your trip, while online options like GCash and PayMaya allow for immediate payment. Click on a payment method to see more details.</p>
                            </div>
                        </div>
                    `;
                    paymentSelectionCard.insertBefore(guideElement, paymentMethods);
                }
            }

            // Initialize discount ID proof upload functionality
            setupDiscountIdUpload();
        });

        // Function to set up discount ID proof upload
        function setupDiscountIdUpload() {
            const discountIdInput = document.getElementById('discount_id_proof');
            const idPreview = document.getElementById('id-preview');
            const idUploadSection = document.getElementById('id-upload-section');
            
            if (!discountIdInput || !idPreview) return;
            
            discountIdInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                if (!validTypes.includes(file.type)) {
                    alert('Please upload a JPG, PNG, GIF, or PDF file for your ID proof.');
                    discountIdInput.value = '';
                    return;
                }
                
                // Validate file size (5MB limit)
                const fileSize = file.size / 1024 / 1024; // in MB
                if (fileSize > 5) {
                    alert('File size exceeds 5MB. Please upload a smaller file.');
                    discountIdInput.value = '';
                    return;
                }
                
                // Create preview
                const reader = new FileReader();
                reader.onload = function(event) {
                    idPreview.classList.remove('d-none');
                    
                    // Set preview content based on file type
                    if (file.type.includes('image')) {
                        idPreview.innerHTML = `
                            <div class="d-flex flex-column align-items-center">
                                <img src="${event.target.result}" class="img-thumbnail mb-2" style="max-height: 200px;">
                                <div class="id-preview-filename text-center mb-2">${file.name}</div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary change-id-preview">
                                        <i class="fas fa-sync me-1"></i> Change
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-id-preview">
                                        <i class="fas fa-trash me-1"></i> Remove
                                    </button>
                                </div>
                            </div>
                        `;
                    } else {
                        // For PDF files
                        idPreview.innerHTML = `
                            <div class="d-flex flex-column align-items-center">
                                <div class="bg-light p-4 mb-2 rounded">
                                    <i class="fas fa-file-pdf fa-3x text-danger"></i>
                                </div>
                                <div class="id-preview-filename text-center mb-2">${file.name}</div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary change-id-preview">
                                        <i class="fas fa-sync me-1"></i> Change
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-id-preview">
                                        <i class="fas fa-trash me-1"></i> Remove
                                    </button>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Set up event listeners for the buttons
                    idPreview.querySelector('.change-id-preview').addEventListener('click', function() {
                        discountIdInput.click();
                    });
                    
                    idPreview.querySelector('.remove-id-preview').addEventListener('click', function() {
                        discountIdInput.value = '';
                        idPreview.classList.add('d-none');
                    });
                };
                
                reader.readAsDataURL(file);
            });
        }

        // Handle payment proof file uploads
        document.addEventListener('DOMContentLoaded', function() {
            // File upload handling for both payment methods
            setupFileUpload('gcash_payment_proof');
            setupFileUpload('paymaya_payment_proof');
            
            // Update the booking form to include file upload
            const bookingForm = document.getElementById('bookingForm');
            if (bookingForm) {
                bookingForm.setAttribute('enctype', 'multipart/form-data');
                
                // Add a hidden input for the payment proof
                const paymentProofInput = document.createElement('input');
                paymentProofInput.type = 'hidden';
                paymentProofInput.id = 'payment_proof_file';
                paymentProofInput.name = 'payment_proof_file';
                bookingForm.appendChild(paymentProofInput);
            }
            
            // Update validation to check for payment proof when required
            if (bookingForm) {
                const originalSubmitHandler = bookingForm.onsubmit;
                
                bookingForm.onsubmit = function(e) {
                    // First check if there's an original handler and call it
                    if (originalSubmitHandler) {
                        // If it returns false, stop processing
                        if (originalSubmitHandler.call(this, e) === false) {
                            return false;
                        }
                    }
                    
                    // Check discount ID proof if discount is selected
                    const selectedDiscount = document.querySelector('input[name="discount_type"]:checked');
                    if (selectedDiscount && selectedDiscount.value !== 'regular') {
                        const discountIdInput = document.getElementById('discount_id_proof');
                        if (!discountIdInput || !discountIdInput.files || discountIdInput.files.length === 0) {
                            e.preventDefault();
                            alert(`Please upload your ${selectedDiscount.value} ID for verification of your discount.`);
                            document.getElementById('id-upload-section').scrollIntoView({ behavior: 'smooth' });
                            return false;
                        }
                    }
                    
                    // Get selected payment method
                    const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
                    if (!paymentMethod) {
                        e.preventDefault();
                        alert('Please select a payment method');
                        document.getElementById('payment-selection').scrollIntoView({ behavior: 'smooth' });
                        return false;
                    }
                    
                    // Check if payment proof is required
                    if (paymentMethod.value === 'gcash' || paymentMethod.value === 'paymaya') {
                        const fileInput = document.getElementById(paymentMethod.value + '_payment_proof');
                        
                        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                            e.preventDefault();
                            alert('Please upload a payment proof screenshot for ' + paymentMethod.value.toUpperCase());
                            fileInput.focus();
                            return false;
                        }
                        
                        // Create FormData to properly include all files
                        const formData = new FormData(bookingForm);
                        
                        // Add payment proof file
                        formData.append(paymentMethod.value + '_payment_proof', fileInput.files[0]);
                        
                        // Add discount ID proof if applicable
                        if (selectedDiscount && selectedDiscount.value !== 'regular') {
                            const discountIdInput = document.getElementById('discount_id_proof');
                            if (discountIdInput && discountIdInput.files.length > 0) {
                                formData.append('discount_id_proof', discountIdInput.files[0]);
                            }
                        }
                        
                        // Stop the normal form submission
                        e.preventDefault();
                        
                        // Show loading overlay
                        document.body.insertAdjacentHTML('beforeend', `
                            <div id="loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                                background-color: rgba(0,0,0,0.7); z-index: 9999; display: flex; 
                                justify-content: center; align-items: center;">
                                <div class="card p-4 text-center">
                                    <div class="spinner-border text-primary mb-3" role="status">
                                        <span class="visually-hidden">Processing booking and uploading proof...</span>
                                    </div>
                                    <h5>Processing your booking...</h5>
                                    <p>Uploading payment proof. Please wait, this may take a few moments.</p>
                                </div>
                            </div>
                        `);
                        
                        // Submit the form with FormData using fetch
                        fetch(bookingForm.action, {
                            method: 'POST',
                            body: formData,
                        })
                        .then(response => {
                            if (response.redirected) {
                                window.location.href = response.url;
                            } else {
                                window.location.reload();
                            }
                        })
                        .catch(error => {
                            document.getElementById('loading-overlay').remove();
                            alert('Error submitting booking: ' + error.message);
                            console.error('Error:', error);
                        });
                        
                        return false;
                    }
                    
                    // For counter payment (no file upload needed), use normal form submission
                    document.body.insertAdjacentHTML('beforeend', `
                        <div id="loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                            background-color: rgba(0,0,0,0.7); z-index: 9999; display: flex; 
                            justify-content: center; align-items: center;">
                            <div class="card p-4 text-center">
                                <div class="spinner-border text-primary mb-3" role="status">
                                    <span class="visually-hidden">Processing booking...</span>
                                </div>
                                <h5>Processing your booking...</h5>
                                <p>Please wait, this may take a few moments.</p>
                            </div>
                        </div>
                    `);
                };
            }
            
            // Update payment method selection to set active file input
            document.querySelectorAll('.payment-method-option').forEach(function(option) {
                option.addEventListener('click', function() {
                    const paymentMethod = this.getAttribute('data-payment');
                    
                    // Hide all payment proof upload sections
                    document.querySelectorAll('.payment-proof-upload').forEach(function(upload) {
                        upload.classList.remove('active-upload');
                        upload.style.opacity = '0.5';
                    });
                    
                    // Show only the active payment method's upload section
                    const activeUpload = this.querySelector('.payment-proof-upload');
                    if (activeUpload) {
                        activeUpload.classList.add('active-upload');
                        activeUpload.style.opacity = '1';
                    }
                });
            });
        });

        // Function to set up file upload handling
        function setupFileUpload(inputId) {
            const fileInput = document.getElementById(inputId);
            if (!fileInput) return;
            
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // Validate file type
                const fileType = file.type;
                if (fileType !== 'image/jpeg' && fileType !== 'image/png') {
                    alert('Please upload a JPG or PNG image file.');
                    fileInput.value = '';
                    return;
                }
                
                // Check file size (5MB limit)
                const fileSize = file.size / 1024 / 1024; // Convert to MB
                if (fileSize > 5) {
                    alert('File size exceeds 5MB. Please upload a smaller image.');
                    fileInput.value = '';
                    return;
                }
                
                // Get preview container
                const previewContainer = fileInput.nextElementSibling.nextElementSibling;
                if (!previewContainer) return;
                
                // Create a preview
                const reader = new FileReader();
                reader.onload = function(event) {
                    // Show preview container
                    previewContainer.classList.remove('d-none');
                    
                    // Set image source
                    const previewImg = previewContainer.querySelector('img');
                    if (previewImg) {
                        previewImg.src = event.target.result;
                    }
                    
                    // Set filename
                    const filenameElement = previewContainer.querySelector('.preview-filename');
                    if (filenameElement) {
                        filenameElement.textContent = file.name;
                    }
                };
                reader.readAsDataURL(file);
                
                // Set up remove button
                const removeButton = previewContainer.querySelector('.remove-preview');
                if (removeButton) {
                    removeButton.onclick = function() {
                        fileInput.value = '';
                        previewContainer.classList.add('d-none');
                    };
                }
                
                // Set up change button
                const changeButton = previewContainer.querySelector('.change-preview');
                if (changeButton) {
                    changeButton.onclick = function() {
                        fileInput.click();
                    };
                }
            });
        }

        // Discount selection and fare calculation
        document.addEventListener('DOMContentLoaded', function() {
            const discountOptions = document.querySelectorAll('.discount-option');
            const idUploadSection = document.getElementById('id-upload-section');
            let originalFare = 0;
            
            discountOptions.forEach(function(option) {
                option.addEventListener('change', function() {
                    const discountType = this.value;
                    
                    // Show ID upload section for all discount types except regular
                    if (discountType !== 'regular') {
                        idUploadSection.style.display = 'block';
                    } else {
                        idUploadSection.style.display = 'none';
                    }
                    
                    // Update fare amount with discount
                    updateFareWithDiscount(discountType);
                });
            });
            
            // Function to update fare with discount
            function updateFareWithDiscount(discountType) {
                const fareElement = document.getElementById('summary_fare');
                if (!fareElement) return;
                
                const fareText = fareElement.textContent;
                const fareMatch = fareText.match(/[\d,]+(\.\d+)?/);
                
                if (!fareMatch) return;
                
                const currentFare = parseFloat(fareMatch[0].replace(/,/g, ''));
                
                // Store the original fare if not already stored
                if (originalFare === 0) {
                    originalFare = currentFare;
                }
                
                // Calculate discount
                let discountedFare = originalFare;
                let discountLabel = '';
                
                if (discountType === 'student' || discountType === 'senior' || discountType === 'pwd') {
                    // Apply 20% discount
                    discountedFare = originalFare * 0.8;
                    discountLabel = ' (20% Discount Applied)';
                }
                
                // Update hidden discount type field
                const hiddenDiscountInput = document.getElementById('summary_discount_type');
                if (hiddenDiscountInput) {
                    hiddenDiscountInput.value = discountType;
                } else {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.id = 'summary_discount_type';
                    input.name = 'discount_type';
                    input.value = discountType;
                    document.getElementById('bookingForm').appendChild(input);
                }
                
                // Update the fare display
                fareElement.innerHTML = `₱${discountedFare.toFixed(2)}${discountLabel}`;
                
                // Update fare amount labels in payment instructions
                document.querySelectorAll('.fare-amount').forEach(function(el) {
                    el.textContent = discountedFare.toFixed(2);
                });
                
                // Add highlight effect
                fareElement.classList.add('fare-updated');
                setTimeout(() => {
                    fareElement.classList.remove('fare-updated');
                }, 2000);
            }
        });

        document.addEventListener("DOMContentLoaded", function () {
            const selectedTrip = "<?php echo $selected_trip; ?>";
            if (selectedTrip) {
                document.querySelectorAll(".bus-card").forEach(card => {
                    const tripNumber = card.getAttribute("data-trip-number");
                    if (tripNumber !== selectedTrip) {
                        card.style.display = "none"; // Hide other trips
                    }
                });
            }
        });

        // Handle discount type changes
        document.addEventListener('DOMContentLoaded', function() {
            // Discount ID Proof Handling
            const discountTypeRadios = document.querySelectorAll('input[name="discount_type"]');
            const discountProofSection = document.getElementById('discount-proof-section');
            const discountIdInput = document.getElementById('discount_id_proof');
            const idPreview = document.getElementById('id-preview');
            
            discountTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('summary_discount_type').value = this.value;
                    if (this.value !== 'regular') {
                        discountProofSection.style.display = 'block';
                    } else {
                        discountProofSection.style.display = 'none';
                        discountIdInput.value = '';
                        idPreview.classList.add('d-none');
                    }
                });
            });
            
            // Discount ID Proof Preview
            discountIdInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // Validate file
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                if (!validTypes.includes(file.type)) {
                    showError('Please upload a JPG, PNG, GIF, or PDF file for your ID proof.');
                    this.value = '';
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) { // 5MB
                    showError('File size exceeds 5MB. Please upload a smaller file.');
                    this.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    idPreview.classList.remove('d-none');
                    
                    if (file.type.includes('image')) {
                        document.getElementById('id-preview-image').src = e.target.result;
                        document.getElementById('id-preview-image').style.display = 'block';
                        document.getElementById('id-preview-pdf').style.display = 'none';
                    } else {
                        document.getElementById('id-preview-image').style.display = 'none';
                        document.getElementById('id-preview-pdf').style.display = 'block';
                    }
                    
                    document.querySelector('.id-preview-filename').textContent = file.name;
                };
                reader.readAsDataURL(file);
            });
            
            // Payment Proof Handling (shown when payment method requires it)
            const paymentMethodRadios = document.querySelectorAll('input[name="payment_method"]');
            const paymentProofSection = document.getElementById('payment-proof-section');
            const paymentProofInput = document.getElementById('payment_proof');
            const paymentPreview = document.getElementById('payment-preview');
            
            paymentMethodRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('summary_payment_method').value = this.value;
                    if (this.value === 'gcash' || this.value === 'paymaya') {
                        paymentProofSection.style.display = 'block';
                    } else {
                        paymentProofSection.style.display = 'none';
                        paymentProofInput.value = '';
                        paymentPreview.classList.add('d-none');
                    }
                });
            });
            
            // Payment Proof Preview
            paymentProofInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // Validate file
                if (!file.type.includes('image')) {
                    showError('Please upload an image file (JPG or PNG) for payment proof.');
                    this.value = '';
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) { // 5MB
                    showError('File size exceeds 5MB. Please upload a smaller image.');
                    this.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    paymentPreview.classList.remove('d-none');
                    document.getElementById('payment-preview-image').src = e.target.result;
                    document.querySelector('.payment-preview-filename').textContent = file.name;
                };
                reader.readAsDataURL(file);
            });
            
            // Form submission
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                // Validate discount proof if needed
                const discountType = document.getElementById('summary_discount_type').value;
                if (discountType !== 'regular' && !discountIdInput.files.length) {
                    e.preventDefault();
                    showError('Please upload your discount ID proof');
                    return false;
                }
                
                // Validate payment proof if needed
                const paymentMethod = document.getElementById('summary_payment_method').value;
                if ((paymentMethod === 'gcash' || paymentMethod === 'paymaya') && !paymentProofInput.files.length) {
                    e.preventDefault();
                    showError('Please upload your payment proof');
                    return false;
                }
                
                // Show loading state
                document.getElementById('submit-text').classList.add('d-none');
                document.getElementById('submit-spinner').classList.remove('d-none');
            });
            
            // Helper function to show errors
            function showError(message) {
                const errorDiv = document.getElementById('booking-errors');
                errorDiv.textContent = message;
                errorDiv.classList.remove('d-none');
                errorDiv.scrollIntoView({ behavior: 'smooth' });
            }
            
            // Set up preview button handlers
            document.querySelector('.change-id-preview')?.addEventListener('click', () => discountIdInput.click());
            document.querySelector('.remove-id-preview')?.addEventListener('click', () => {
                discountIdInput.value = '';
                idPreview.classList.add('d-none');
            });
            
            document.querySelector('.change-payment-preview')?.addEventListener('click', () => paymentProofInput.click());
            document.querySelector('.remove-payment-preview')?.addEventListener('click', () => {
                paymentProofInput.value = '';
                paymentPreview.classList.add('d-none');
            });
        });

        // Add CSS styles for payment proof upload sections
        const proofUploadStyles = document.createElement('style');
        proofUploadStyles.textContent = `
            .payment-proof-upload {
                transition: opacity 0.3s ease;
                padding: 10px;
                border-radius: 5px;
                background-color: rgba(0,0,0,0.02);
            }
            
            .payment-proof-upload.active-upload {
                background-color: rgba(0,123,255,0.05);
            }
            
            .payment-preview {
                transition: all 0.3s ease;
            }
            
            .payment-preview img {
                object-fit: cover;
            }
            
            .fare-updated {
                animation: fareUpdate 1s ease;
            }
            
            @keyframes fareUpdate {
                0% { background-color: transparent; }
                50% { background-color: rgba(255, 193, 7, 0.3); }
                100% { background-color: transparent; }
            }
        `;
        document.head.appendChild(proofUploadStyles);
        
        // Initialize: Trigger origin change to set initial disabled states
        const originSelect = document.getElementById('origin');
        if (originSelect.value) {
            const event = new Event('change');
            originSelect.dispatchEvent(event);
        }
    </script>
</body>
</html>