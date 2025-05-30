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
$booking_references = [];
$transaction_started = false;

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_tickets'])) {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $booking_date = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    $passengers = isset($_POST['passengers']) ? $_POST['passengers'] : [];
    
    // Enhanced validation
    $errors = [];
    if ($bus_id <= 0) {
        $errors[] = "Please select a valid bus";
    }
    if (empty($booking_date)) {
        $errors[] = "Please select a travel date";
    } else {
        // Validate date format and ensure it's not 0000-00-00
        $date = DateTime::createFromFormat('Y-m-d', $booking_date);
        if (!$date || $date->format('Y-m-d') !== $booking_date || $booking_date === '0000-00-00') {
            $errors[] = "Invalid date format";
        } elseif ($date < new DateTime('today')) {
            $errors[] = "Booking date cannot be in the past";
        }
    }
    if (empty($payment_method)) {
        $errors[] = "Please select a payment method";
    }
    if (empty($passengers)) {
        $errors[] = "Please add at least one passenger";
    }
    
    // Validate each passenger
    foreach ($passengers as $index => $passenger) {
        $passengerNum = $index + 1;
        
        if (empty($passenger['name']) || trim($passenger['name']) === '') {
            $errors[] = "Please enter name for passenger #{$passengerNum}";
        }
        if (empty($passenger['seat_number']) || intval($passenger['seat_number']) <= 0) {
            $errors[] = "Please select a seat for passenger #{$passengerNum}";
        }
        if (empty($passenger['discount_type'])) {
            $errors[] = "Please select discount type for passenger #{$passengerNum}";
        }
        
        // Validate discount ID proof if discount is selected
        if ($passenger['discount_type'] !== 'regular') {
            $fileKey = 'discount_id_proof_' . $index;
            if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Please upload valid ID for passenger #{$passengerNum} (" . ucfirst($passenger['discount_type']) . " discount)";
            }
        }
    }
    
    // Validate payment proof for online payment methods
    $payment_proof_path = null;
    if (($payment_method === 'gcash' || $payment_method === 'paymaya') && 
        (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK)) {
        $errors[] = "Please upload payment proof for " . strtoupper($payment_method);
    }
    
    // Process booking if no errors
    if (empty($errors)) {
        try {
            // Start transaction
            $conn->begin_transaction();
            $transaction_started = true;
            
            // Process payment proof upload if applicable
            if ($payment_method === 'gcash' || $payment_method === 'paymaya') {
                $payment_proof_path = processPaymentProofUpload($payment_method);
                
                if (!$payment_proof_path) {
                    throw new Exception("Failed to upload payment proof. Please try again.");
                }
            }
            
            // Check if any seats are already booked
            $seat_numbers = array_column($passengers, 'seat_number');
            $seat_placeholders = str_repeat('?,', count($seat_numbers) - 1) . '?';
            $check_query = "SELECT seat_number FROM bookings 
                        WHERE bus_id = ? 
                        AND booking_date = ? 
                        AND seat_number IN ($seat_placeholders) 
                        AND booking_status = 'confirmed'";
            
            $check_stmt = $conn->prepare($check_query);
            if (!$check_stmt) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $params = array_merge([$bus_id, $booking_date], $seat_numbers);
            $types = 'is' . str_repeat('i', count($seat_numbers));
            $check_stmt->bind_param($types, ...$params);
            
            if (!$check_stmt->execute()) {
                throw new Exception("Database execution error: " . $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $booked_seats = [];
                while ($row = $check_result->fetch_assoc()) {
                    $booked_seats[] = $row['seat_number'];
                }
                throw new Exception("The following seats are already booked: " . implode(', ', $booked_seats) . ". Please select different seats.");
            }
            
            // Get trip number for this bus
            $trip_number = null;
            $trip_query = "SELECT trip_number FROM schedules WHERE bus_id = ? AND status = 'active' LIMIT 1";
            $trip_stmt = $conn->prepare($trip_query);
            if ($trip_stmt) {
                $trip_stmt->bind_param("i", $bus_id);
                $trip_stmt->execute();
                $trip_result = $trip_stmt->get_result();
                if ($trip_result && $trip_result->num_rows > 0) {
                    $trip_data = $trip_result->fetch_assoc();
                    $trip_number = $trip_data['trip_number'];
                }
            }
            
            // Set payment status based on payment method
            $payment_status = ($payment_method === 'counter') ? 'not_required' : 
                            (($payment_method === 'gcash' || $payment_method === 'paymaya') ? 'awaiting_verification' : 'pending');
            
            // Set payment proof status
            $payment_proof_status = ($payment_method === 'counter') ? 'not_required' : 
                                ($payment_proof_path ? 'uploaded' : 'pending');
            
            // Get current timestamp for payment proof upload
            $current_timestamp = date('Y-m-d H:i:s');
            
            // Process each passenger booking
            $booking_references = [];
            
            foreach ($passengers as $index => $passenger) {
                // Process discount ID upload if applicable
                $discount_id_path = null;
                if ($passenger['discount_type'] !== 'regular') {
                    $discount_id_path = processDiscountIDUpload($passenger['discount_type'], $index);
                    
                    if (!$discount_id_path) {
                        throw new Exception("Failed to upload discount ID proof for passenger: " . $passenger['name']);
                    }
                }
                
                // Generate unique booking reference
                $booking_reference = 'BK-' . date('Ymd') . '-' . uniqid();
                
                // Enhanced insert query with all required fields
                $insert_query = "INSERT INTO bookings (
                    bus_id, user_id, passenger_name, seat_number, booking_date, 
                    booking_status, created_at, booking_reference, trip_number, 
                    payment_method, payment_status, payment_proof, payment_proof_status, 
                    payment_proof_timestamp, discount_type, discount_id_proof, discount_verified
                ) VALUES (?, ?, ?, ?, ?, 'confirmed', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

                $insert_stmt = $conn->prepare($insert_query);
                if (!$insert_stmt) {
                    throw new Exception("Database prepare error for booking insert: " . $conn->error);
                }
                
                // Ensure passenger name is properly trimmed
                $passenger_name = trim($passenger['name']);
                
                $insert_stmt->bind_param("iississsssssss", 
                    $bus_id, 
                    $user_id, 
                    $passenger_name,
                    $passenger['seat_number'], 
                    $booking_date, 
                    $booking_reference, 
                    $trip_number, 
                    $payment_method, 
                    $payment_status, 
                    $payment_proof_path, 
                    $payment_proof_status, 
                    $current_timestamp,
                    $passenger['discount_type'], 
                    $discount_id_path
                );
                
                if (!$insert_stmt->execute()) {
                    throw new Exception("Error creating booking for passenger: " . $passenger['name'] . ". Database error: " . $insert_stmt->error);
                }
                
                $booking_references[] = $booking_reference;
                
                // Log successful booking
                error_log("Successfully created booking: " . $booking_reference . " for passenger: " . $passenger_name);
            }
            
            // Commit the transaction
            $conn->commit();
            $transaction_started = false;
            
            // Set success flag
            $booking_success = true;
            
            // Log successful completion
            error_log("Booking process completed successfully. References: " . implode(', ', $booking_references));
            
            // Redirect to booking receipt page with booking references
            $booking_refs = implode(',', $booking_references);
            $redirect_url = "booking_receipt.php?booking_refs=" . urlencode($booking_refs);
            
            // Clear any output buffer and redirect
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            header("Location: " . $redirect_url);
            exit();
            
        } catch (Exception $e) {
            // Rollback in case of any exception
            if (isset($transaction_started) && $transaction_started) {
                $conn->rollback();
            }
            
            // Log the error
            error_log("Booking error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $booking_error = "Booking failed: " . $e->getMessage();
        }
    } else {
        $booking_error = implode(", ", $errors);
        error_log("Booking validation errors: " . $booking_error);
    }
}

// Enhanced processPaymentProofUpload function
function processPaymentProofUpload($payment_method) {
    try {
        // Check if file was uploaded
        if (!isset($_FILES['payment_proof']) || 
            $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
            error_log("Payment proof file upload error: " . ($_FILES['payment_proof']['error'] ?? 'No file'));
            return null;
        }
        
        $file = $_FILES['payment_proof'];
        
        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/uploads/payment_proofs/';
        
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                error_log("Failed to create upload directory: " . $upload_dir);
                return null;
            }
        }
        
        // Validate file type
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || 
            !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            error_log("Invalid file type for payment proof");
            return null;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            error_log("File size too large: " . $file['size']);
            return null;
        }
        
        // Generate a unique filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $payment_method . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        // Move the uploaded file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Return the relative path to store in database
            $relative_path = 'uploads/payment_proofs/' . $unique_filename;
            error_log("Payment proof uploaded successfully: " . $relative_path);
            return $relative_path;
        } else {
            error_log("Failed to move uploaded file to: " . $upload_path);
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error processing payment proof upload: " . $e->getMessage());
        return null;
    }
}

// Enhanced processDiscountIDUpload function
function processDiscountIDUpload($discount_type, $passenger_index) {
    try {
        $file_key = 'discount_id_proof_' . $passenger_index;
        
        // Check if file was uploaded
        if (!isset($_FILES[$file_key]) || 
            $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            error_log("Discount ID file not uploaded or has error for passenger {$passenger_index}");
            return null;
        }
        
        $file = $_FILES[$file_key];
        
        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/uploads/discount_ids/';
        
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                error_log("Failed to create discount ID upload directory: " . $upload_dir);
                return null;
            }
        }
        
        // Validate file type
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || 
            !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            error_log("Invalid file type for discount ID for passenger {$passenger_index}");
            return null;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            error_log("File too large for discount ID for passenger {$passenger_index}");
            return null;
        }
        
        // Generate a unique filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $discount_type . '_id_' . $passenger_index . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        // Move the uploaded file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            // Return the relative path to store in database
            $relative_path = 'uploads/discount_ids/' . $unique_filename;
            error_log("Discount ID uploaded successfully: " . $relative_path);
            return $relative_path;
        } else {
            error_log("Failed to move discount ID file to: " . $upload_path);
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error processing discount ID upload for passenger {$passenger_index}: " . $e->getMessage());
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
    <title>Book Tickets - ISAT-U Ceres Bus Ticket System</title>
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
            transition: all 0.3s ease;
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
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            border-color: #fff;
            z-index: 5;
        }
        
        .seat.booked {
            background-color: #dc3545;
            cursor: not-allowed;
            opacity: 0.8;
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
            border-radius: 5px;
        }
        
        .seat.selected {
            background-color: #007bff;
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(0,123,255,0.6);
            z-index: 10;
            border-color: #fff;
            animation: pulseSelected 2s infinite;
        }
        
        @keyframes pulseSelected {
            0% { box-shadow: 0 0 15px rgba(0,123,255,0.6); }
            50% { box-shadow: 0 0 25px rgba(0,123,255,0.8); }
            100% { box-shadow: 0 0 15px rgba(0,123,255,0.6); }
        }
        
        .passenger-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            background-color: #fff;
            transition: all 0.3s;
        }
        
        .passenger-card.active {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        .passenger-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .discount-selector {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .discount-option {
            padding: 15px;
            margin: 8px 0;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #fff;
            position: relative;
        }
        
        .discount-option:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .discount-option.selected {
            border-color: #007bff;
            background-color: #e7f1ff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        .discount-option .form-check {
            margin-bottom: 0;
            pointer-events: none; /* Prevent the form-check from interfering with click events */
        }
        
        .discount-option .form-check-input {
            pointer-events: auto; /* Re-enable pointer events for the radio button itself */
        }
        
        .discount-option .form-check-label {
            cursor: pointer;
            width: 100%;
            margin-bottom: 0;
        }
        
        .discount-option .form-check-input:checked + .form-check-label {
            color: #007bff;
        }
        
        .discount-option .form-check-input:checked + .form-check-label strong {
            color: #0056b3;
        }
        
        .fare-display {
            font-size: 1.2rem;
            font-weight: bold;
            color: #28a745;
        }
        
        .fare-original {
            text-decoration: line-through;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .remove-passenger-btn {
            background-color: #dc3545;
            border: none;
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .remove-passenger-btn:hover {
            background-color: #c82333;
            transform: scale(1.1);
        }
        
        .add-passenger-btn {
            border: 2px dashed #007bff;
            background-color: #f8f9fa;
            color: #007bff;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .add-passenger-btn:hover {
            background-color: #e7f1ff;
            border-color: #0056b3;
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
        
        .booking-summary {
            position: sticky;
            top: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .total-amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
            border-top: 2px solid #e9ecef;
            padding-top: 15px;
            margin-top: 15px;
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

        .id-upload-section {
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #dc3545;
        }

        .file-preview {
            margin-top: 10px;
            padding: 10px;
            background-color: #fff;
            border-radius: 6px;
            border: 1px solid #dee2e6;
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
        
        .fare-breakdown {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .fare-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .fare-item:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 1.1rem;
            color: #28a745;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
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
                        <a class="nav-link active" href="booking.php">Book Tickets</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="locations.php">Locations</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="fares.php">Fares</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-ticket-alt me-2"></i>Book Your Tickets</h2>
                
                <?php if ($booking_success): ?>
                <!-- Booking Success Message -->
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Booking Successful!</h4>
                    <p>Your tickets have been booked successfully. Your booking references are: <strong><?php echo implode(', ', $booking_references); ?></strong></p>
                    <hr>
                    <p class="mb-0">You can view your booking details in <a href="mybookings.php" class="alert-link">My Bookings</a> page.</p>
                </div>
                <?php elseif (!empty($booking_error)): ?>
                <!-- Booking Error Message -->
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $booking_error; ?>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Route Selection -->
                        <div class="card mb-4">
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
                        <!-- Bus Selection -->
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

                        <!-- Passengers and Seat Selection -->
                        <div class="card mb-4" id="passenger-selection" style="display: none;">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Add Passengers & Select Seats</h5>
                            </div>
                            <div class="card-body">
                                <div id="passengers-container">
                                    <!-- Passengers will be dynamically added here -->
                                </div>
                                
                                <div class="add-passenger-btn" id="add-passenger-btn">
                                    <i class="fas fa-plus-circle fa-2x mb-2"></i>
                                    <h5>Add Passenger</h5>
                                    <p class="mb-0">Click to add another passenger</p>
                                </div>

                                <!-- Seat Map -->
                                <div class="card mt-4" id="seat-map-card" style="display: none;">
                                    <div class="card-header bg-info text-white">
                                        <h5 class="mb-0"><i class="fas fa-chair me-2"></i>Select Seats</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="text-center mb-4">
                                            <div class="driver-area">
                                                <i class="fas fa-steering-wheel me-1"></i> Driver Area
                                            </div>
                                        </div>
                                        
                                        <div class="seat-map-container">
                                            <div id="seatMapContainer" class="d-flex flex-column align-items-center justify-content-center">
                                                <!-- Seat map will be dynamically loaded here -->
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Seat Selection Guide:</strong><br>
                                            • <span class="badge bg-success me-1">Green seats</span> are available for booking<br>
                                            • <span class="badge bg-danger me-1">Red seats</span> are already booked<br>
                                            • <span class="badge bg-primary me-1">Blue seats</span> are your current selections<br>
                                            • Click on any available seat or use the <i class="fas fa-chair"></i> button next to each passenger<br>
                                            • For multiple passengers, you can choose who gets which seat
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Method Selection -->
                        <div class="card mb-4" id="payment-selection" style="display: none;">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Select Payment Method</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-4">
                                    <i class="fas fa-info-circle me-2"></i>Please select your preferred payment method and upload any required documents.
                                </div>
                                
                                <div class="payment-methods">
                                    <!-- Over the Counter Payment -->
                                    <div class="payment-method-option d-flex" data-payment="counter" onclick="selectPaymentMethod(this)">
                                        <div class="payment-method-logo">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <div class="payment-method-info flex-grow-1">
                                            <h5 class="mb-1">Pay Over the Counter</h5>
                                            <p class="mb-2 text-muted">Pay directly at the bus terminal before your trip</p>
                                            <div class="payment-instructions" style="display: none;">
                                                <div class="card card-body bg-light">
                                                    <p class="mb-0"><strong>How it works:</strong></p>
                                                    <ol class="mb-0 ps-3">
                                                        <li>Arrive at the terminal at least 30 minutes before departure</li>
                                                        <li>Present your booking reference at the counter</li>
                                                        <li>Pay the exact amount in cash</li>
                                                        <li>Receive your physical tickets and boarding passes</li>
                                                    </ol>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="payment-radio form-check align-self-start">
                                            <input class="form-check-input" type="radio" name="payment_method" id="payment_counter" value="counter">
                                        </div>
                                    </div>
                                    
                                    <!-- GCash Payment -->
                                    <div class="payment-method-option d-flex" data-payment="gcash" onclick="selectPaymentMethod(this)">
                                        <div class="payment-method-logo">
                                            <i class="fas fa-mobile-alt"></i>
                                        </div>
                                        <div class="payment-method-info flex-grow-1">
                                            <h5 class="mb-1">GCash</h5>
                                            <p class="mb-2 text-muted">Pay instantly using your GCash account</p>
                                            <div class="payment-instructions" style="display: none;">
                                                <div class="card card-body bg-light">
                                                    <div class="row">
                                                        <div class="col-md-8">
                                                            <p class="mb-2"><strong>Total Amount to Pay: ₱<span id="gcash-total">0.00</span></strong></p>
                                                            <p class="mb-2"><strong>How to pay via GCash:</strong></p>
                                                            <ol class="mb-3 ps-3">
                                                                <li>Open your GCash app</li>
                                                                <li>Tap on "Scan QR Code"</li>
                                                                <li>Scan the QR code shown here</li>
                                                                <li>Enter the exact amount: ₱<span class="gcash-amount">0.00</span></li>
                                                                <li>Confirm the payment</li>
                                                                <li>Take a screenshot and upload below</li>
                                                            </ol>
                                                            
                                                            <div class="mt-3">
                                                                <label for="payment_proof" class="form-label"><strong>Upload Payment Screenshot:</strong></label>
                                                                <input class="form-control" id="payment_proof" name="payment_proof" type="file" accept="image/*" required>
                                                                <div class="form-text">Supported formats: JPG, PNG (Max 5MB)</div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 text-center">
                                                            <div class="qr-code-container p-2 bg-white border rounded mb-2">
                                                                <div class="qr-placeholder border border-primary p-4 rounded bg-light text-center" style="width: 150px; height: 150px; margin: 0 auto;">
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
                                        <div class="payment-radio form-check align-self-start">
                                            <input class="form-check-input" type="radio" name="payment_method" id="payment_gcash" value="gcash">
                                        </div>
                                    </div>

                                    <!-- PayMaya Payment -->
                                    <div class="payment-method-option d-flex" data-payment="paymaya" onclick="selectPaymentMethod(this)">
                                        <div class="payment-method-logo">
                                            <i class="fas fa-credit-card"></i>
                                        </div>
                                        <div class="payment-method-info flex-grow-1">
                                            <h5 class="mb-1">PayMaya</h5>
                                            <p class="mb-2 text-muted">Secure online payment using PayMaya</p>
                                            <div class="payment-instructions" style="display: none;">
                                                <div class="card card-body bg-light">
                                                    <div class="row">
                                                        <div class="col-md-8">
                                                            <p class="mb-2"><strong>Total Amount to Pay: ₱<span id="paymaya-total">0.00</span></strong></p>
                                                            <p class="mb-2"><strong>How to pay via PayMaya:</strong></p>
                                                            <ol class="mb-3 ps-3">
                                                                <li>Open your PayMaya app</li>
                                                                <li>Select "Scan to Pay"</li>
                                                                <li>Scan the QR code shown here</li>
                                                                <li>Enter the exact amount: ₱<span class="paymaya-amount">0.00</span></li>
                                                                <li>Complete the payment</li>
                                                                <li>Take a screenshot and upload below</li>
                                                            </ol>
                                                            
                                                            <div class="mt-3">
                                                                <label for="payment_proof_paymaya" class="form-label"><strong>Upload Payment Screenshot:</strong></label>
                                                                <input class="form-control" id="payment_proof_paymaya" name="payment_proof" type="file" accept="image/*" required>
                                                                <div class="form-text">Supported formats: JPG, PNG (Max 5MB)</div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 text-center">
                                                            <div class="qr-code-container p-2 bg-white border rounded mb-2">
                                                                <div class="qr-placeholder border border-primary p-4 rounded bg-light text-center" style="width: 150px; height: 150px; margin: 0 auto;">
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
                                        <div class="payment-radio form-check align-self-start">
                                            <input class="form-check-input" type="radio" name="payment_method" id="payment_paymaya" value="paymaya">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Booking Summary -->
                        <div class="booking-summary">
                            <h5 class="mb-3"><i class="fas fa-clipboard-check me-2"></i>Booking Summary</h5>
                            
                            <form action="booking.php" method="POST" id="bookingForm" enctype="multipart/form-data">
                                <input type="hidden" name="bus_id" id="summary_bus_id" value="">
                                <input type="hidden" name="booking_date" id="summary_booking_date" value="<?php echo $selected_date; ?>">
                                <input type="hidden" name="payment_method" id="summary_payment_method" value="">
                                <input type="hidden" name="book_tickets" value="1">
                                <div id="passengers-input-container">
                                    <!-- Passenger hidden inputs will be added here -->
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Booked by</label>
                                    <div class="form-control bg-light"><?php echo htmlspecialchars($user_name); ?></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Route</label>
                                    <div class="form-control bg-light" id="summary_route">
                                        <?php if (!empty($selected_origin) && !empty($selected_destination)): ?>
                                        <?php echo htmlspecialchars($selected_origin); ?> to <?php echo htmlspecialchars($selected_destination); ?>
                                        <?php else: ?>
                                        Not selected
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Travel Date</label>
                                    <div class="form-control bg-light" id="summary_date">
                                        <?php echo date('F d, Y', strtotime($selected_date)); ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Bus Information</label>
                                    <div class="form-control bg-light" id="summary_bus_info">Not selected</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Passengers</label>
                                    <div id="summary_passengers" class="bg-light p-2 rounded">
                                        <em class="text-muted">No passengers added</em>
                                    </div>
                                </div>
                                
                                <div class="fare-breakdown">
                                    <h6 class="mb-3">Fare Breakdown</h6>
                                    <div id="fare-details">
                                        <div class="fare-item">
                                            <span>No passengers added</span>
                                            <span>₱0.00</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="total-amount text-center">
                                    Total: ₱<span id="total-amount">0.00</span>
                                </div>
                                
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-success btn-lg" id="confirmBookingBtn" disabled>
                                        <i class="fas fa-ticket-alt me-2"></i>Confirm Booking
                                    </button>
                                </div>

                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedBusData = null;
        let passengers = [];
        let bookedSeats = [];
        let seatMap = [];
        let selectedSeats = [];
        let baseFare = 0;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add first passenger by default
            addPassenger();
            
            // Setup event listeners
            setupEventListeners();
            
            // Show helpful initial instruction
            showInitialInstruction();
        });

        function showInitialInstruction() {
            setTimeout(() => {
                const firstNameInput = document.querySelector('.passenger-name');
                if (firstNameInput && !firstNameInput.value.trim()) {
                    // Create a subtle hint
                    const hint = document.createElement('div');
                    hint.className = 'alert alert-info mt-2';
                    hint.innerHTML = `
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> Enter the passenger's full name above, and the seat selection will appear automatically!
                    `;
                    
                    firstNameInput.closest('.mb-3').appendChild(hint);
                    
                    // Remove hint when they start typing
                    firstNameInput.addEventListener('input', function() {
                        if (hint.parentNode) {
                            hint.remove();
                        }
                    }, { once: true });
                }
            }, 1000);
        }

        function setupEventListeners() {
            // Bus selection
            document.querySelectorAll('.select-bus').forEach(button => {
                button.addEventListener('click', function() {
                    const busCard = this.closest('.bus-card');
                    selectBus(busCard);
                });
            });

            // Add passenger button
            document.getElementById('add-passenger-btn').addEventListener('click', addPassenger);

            // Payment method selection
            document.querySelectorAll('.payment-method-option').forEach(option => {
                option.addEventListener('click', function() {
                    selectPaymentMethod(this);
                });
            });

            // Form submission
            document.getElementById('bookingForm').addEventListener('submit', handleFormSubmission);
        }

        function selectBus(busCard) {
            // Remove selection from all buses
            document.querySelectorAll('.bus-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Select this bus
            busCard.classList.add('selected');
            
            // Get bus data
            selectedBusData = {
                id: busCard.dataset.busId,
                fare: parseFloat(busCard.dataset.fare),
                type: busCard.dataset.type,
                capacity: parseInt(busCard.dataset.capacity),
                departure: busCard.dataset.departure,
                arrival: busCard.dataset.arrival,
                tripNumber: busCard.dataset.tripNumber
            };
            
            baseFare = selectedBusData.fare;
            
            // Update summary
            updateBusSummary();
            
            // Show passenger selection
            document.getElementById('passenger-selection').style.display = 'block';
            
            // Load seat map
            loadSeatMap();
            
            // Update fare calculations
            updateFareCalculations();
            
            // Check if any passenger already has a name and show seat map
            const hasNamedPassenger = passengers.some(p => p.name && p.name.trim());
            if (hasNamedPassenger) {
                document.getElementById('seat-map-card').style.display = 'block';
            }
            
            // Scroll to passenger selection
            document.getElementById('passenger-selection').scrollIntoView({ behavior: 'smooth' });
        }

        function addPassenger() {
            const passengerIndex = passengers.length;
            const passengerData = {
                id: passengerIndex,
                name: '',
                seatNumber: null,
                discountType: 'regular',
                discountIdFile: null
            };
            
            passengers.push(passengerData);
            
            const passengerCard = createPassengerCard(passengerData);
            document.getElementById('passengers-container').appendChild(passengerCard);
            
            updatePassengersSummary();
            updateFareCalculations();
            
            // Focus on the name input of the newly added passenger
            setTimeout(() => {
                const nameInput = passengerCard.querySelector('.passenger-name');
                if (nameInput) {
                    nameInput.focus();
                }
            }, 100);
            
            // Note: Seat map will show automatically when they enter their name
        }

        function createPassengerCard(passengerData) {
            const card = document.createElement('div');
            card.className = 'passenger-card';
            card.dataset.passengerId = passengerData.id;
            
            card.innerHTML = `
                <div class="passenger-header">
                    <h6 class="mb-0">
                        <i class="fas fa-user me-2"></i>Passenger ${passengerData.id + 1}
                    </h6>
                    <button type="button" class="remove-passenger-btn" onclick="removePassenger(${passengerData.id})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Passenger Name*</label>
                            <input type="text" class="form-control passenger-name" 
                                   placeholder="Enter full name" required
                                   oninput="updatePassengerName(${passengerData.id}, this.value)">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Selected Seat</label>
                            <div class="d-flex gap-2">
                                <div class="form-control bg-light seat-display flex-grow-1" id="seat-display-${passengerData.id}">
                                    Not selected
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="openSeatSelector(${passengerData.id})" title="Choose Seat">
                                    <i class="fas fa-chair"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="discount-selector">
                    <label class="form-label fw-bold">Discount Type</label>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="discount-option selected" data-discount="regular">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="discount_${passengerData.id}" value="regular" checked>
                                    <label class="form-check-label">
                                        <strong>Regular Fare</strong><br>
                                        <small class="text-muted">No discount applied</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="discount-option" data-discount="student">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="discount_${passengerData.id}" value="student">
                                    <label class="form-check-label">
                                        <strong>Student (20% Off)</strong><br>
                                        <small class="text-muted">Must upload Student ID</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="discount-option" data-discount="senior">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="discount_${passengerData.id}" value="senior">
                                    <label class="form-check-label">
                                        <strong>Senior Citizen (20% Off)</strong><br>
                                        <small class="text-muted">Must upload Senior ID</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="discount-option" data-discount="pwd">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="discount_${passengerData.id}" value="pwd">
                                    <label class="form-check-label">
                                        <strong>PWD (20% Off)</strong><br>
                                        <small class="text-muted">Must upload PWD ID</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="id-upload-section" id="id-upload-${passengerData.id}" style="display: none;">
                        <h6><i class="fas fa-id-card me-2"></i>Upload Valid ID for Verification</h6>
                        <input type="file" class="form-control" id="discount_id_proof_${passengerData.id}" 
                               name="discount_id_proof_${passengerData.id}" accept="image/*,.pdf"
                               onchange="handleIdUpload(${passengerData.id}, this)">
                        <div class="form-text">Valid ID must clearly show your name, photo, and ID type. Max 5MB (JPG, PNG, PDF)</div>
                        <div class="file-preview" id="id-preview-${passengerData.id}" style="display: none;">
                            <div class="d-flex align-items-center mt-2">
                                <div class="me-3">
                                    <img class="img-thumbnail" style="max-height: 60px;" alt="ID Preview">
                                </div>
                                <div class="flex-grow-1">
                                    <div class="filename"></div>
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-1" onclick="removeIdFile(${passengerData.id})">
                                        <i class="fas fa-trash me-1"></i>Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="fare-display mt-3">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Base Fare:</small>
                            <div class="fare-original" id="base-fare-${passengerData.id}">₱0.00</div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Final Fare:</small>
                            <div class="fare-amount" id="final-fare-${passengerData.id}">₱0.00</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add event listeners for discount options after creating the card
            setTimeout(() => {
                const discountOptions = card.querySelectorAll('.discount-option');
                discountOptions.forEach(option => {
                    option.addEventListener('click', function(e) {
                        // Prevent event if clicked directly on radio button (let default behavior handle it)
                        if (e.target.type === 'radio') return;
                        
                        const discountType = this.dataset.discount;
                        selectDiscount(passengerData.id, discountType);
                    });
                });
            }, 0);
            
            return card;
        }

        function removePassenger(passengerId) {
            if (passengers.length <= 1) {
                alert('You must have at least one passenger.');
                return;
            }
            
            // Remove from passengers array
            passengers = passengers.filter(p => p.id !== passengerId);
            
            // Remove seat selection if any
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger && passenger.seatNumber) {
                selectedSeats = selectedSeats.filter(seat => seat !== passenger.seatNumber);
                updateSeatMap();
            }
            
            // Remove DOM element
            const card = document.querySelector(`[data-passenger-id="${passengerId}"]`);
            if (card) {
                card.remove();
            }
            
            updatePassengersSummary();
            updateFareCalculations();
            updateSeatMapInstructions();
        }

        function updatePassengerName(passengerId, name) {
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger) {
                passenger.name = name;
                updatePassengersSummary();
                
                // Show seat map automatically when passenger name is entered
                if (name.trim() && selectedBusData) {
                    const seatMapCard = document.getElementById('seat-map-card');
                    if (seatMapCard.style.display === 'none') {
                        seatMapCard.style.display = 'block';
                        
                        // Smooth scroll to seat map with a slight delay
                        setTimeout(() => {
                            seatMapCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 300);
                        
                        // Show helpful instruction
                        showAutoSeatSelectionInstruction(name.trim());
                    }
                }
            }
        }

        function showAutoSeatSelectionInstruction(passengerName) {
            // Remove any existing auto instruction
            const existingInstruction = document.querySelector('.auto-seat-instruction');
            if (existingInstruction) {
                existingInstruction.remove();
            }
            
            // Create instruction
            const instructionHTML = `
                <div class="alert alert-success auto-seat-instruction" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-magic fa-2x text-success"></i>
                        </div>
                        <div>
                            <h6 class="alert-heading mb-1">Great! Now select a seat for ${passengerName}</h6>
                            <p class="mb-0">Click on any <span class="badge bg-success">available seat</span> below, or use the 
                            <button class="btn btn-sm btn-outline-primary" disabled><i class="fas fa-chair"></i></button> 
                            button next to the passenger name to choose a seat.</p>
                        </div>
                        <button type="button" class="btn-close" aria-label="Close" onclick="this.parentElement.parentElement.remove()"></button>
                    </div>
                </div>
            `;
            
            // Insert before seat map container
            const seatMapContainer = document.querySelector('.seat-map-container');
            seatMapContainer.insertAdjacentHTML('beforebegin', instructionHTML);
            
            // Auto-remove instruction after 8 seconds
            setTimeout(() => {
                const instruction = document.querySelector('.auto-seat-instruction');
                if (instruction) {
                    instruction.style.opacity = '0';
                    instruction.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        if (instruction.parentNode) {
                            instruction.remove();
                        }
                    }, 500);
                }
            }, 8000);
        }

        function selectDiscount(passengerId, discountType) {
            const passenger = passengers.find(p => p.id === passengerId);
            if (!passenger) return;
            
            passenger.discountType = discountType;
            
            // Update UI - remove selection from all options for this passenger
            const card = document.querySelector(`[data-passenger-id="${passengerId}"]`);
            card.querySelectorAll('.discount-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Select the clicked option
            const selectedOption = card.querySelector(`[data-discount="${discountType}"]`);
            selectedOption.classList.add('selected');
            
            // Update the radio button
            const radioButton = selectedOption.querySelector('input[type="radio"]');
            if (radioButton) {
                radioButton.checked = true;
            }
            
            // Show/hide ID upload section
            const idUploadSection = document.getElementById(`id-upload-${passengerId}`);
            if (discountType !== 'regular') {
                idUploadSection.style.display = 'block';
                
                // Smooth reveal animation
                idUploadSection.style.opacity = '0';
                idUploadSection.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    idUploadSection.style.transition = 'all 0.3s ease';
                    idUploadSection.style.opacity = '1';
                    idUploadSection.style.transform = 'translateY(0)';
                }, 10);
            } else {
                idUploadSection.style.display = 'none';
                passenger.discountIdFile = null;
                
                // Clear file input
                const fileInput = document.getElementById(`discount_id_proof_${passengerId}`);
                if (fileInput) fileInput.value = '';
                
                // Hide preview
                const preview = document.getElementById(`id-preview-${passengerId}`);
                if (preview) preview.style.display = 'none';
            }
            
            updateFareCalculations();
        }

        function handleIdUpload(passengerId, input) {
            const file = input.files[0];
            if (!file) return;
            
            // Validate file
            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            if (!validTypes.includes(file.type)) {
                alert('Please upload a JPG, PNG, GIF, or PDF file.');
                input.value = '';
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                alert('File size exceeds 5MB. Please upload a smaller file.');
                input.value = '';
                return;
            }
            
            // Update passenger data
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger) {
                passenger.discountIdFile = file;
            }
            
            // Show preview
            const preview = document.getElementById(`id-preview-${passengerId}`);
            const img = preview.querySelector('img');
            const filename = preview.querySelector('.filename');
            
            if (file.type.includes('image')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    img.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                img.style.display = 'none';
            }
            
            filename.textContent = file.name;
            preview.style.display = 'block';
        }

        function removeIdFile(passengerId) {
            const input = document.getElementById(`discount_id_proof_${passengerId}`);
            const preview = document.getElementById(`id-preview-${passengerId}`);
            
            input.value = '';
            preview.style.display = 'none';
            
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger) {
                passenger.discountIdFile = null;
            }
        }

        async function loadSeatMap() {
            if (!selectedBusData) return;
            
            try {
                // Fetch booked seats
                const response = await fetch(`../../backend/connections/get_booked_seats.php?bus_id=${selectedBusData.id}&date=${document.getElementById('date').value}`);
                const data = await response.json();
                bookedSeats = data.bookedSeats || [];
                
                // Generate seat map
                generateSeatMap();
            } catch (error) {
                console.error('Error loading seat map:', error);
                bookedSeats = [];
                generateSeatMap();
            }
        }

        function generateSeatMap() {
            const container = document.getElementById('seatMapContainer');
            container.innerHTML = '';
            
            const totalSeats = selectedBusData.capacity;
            const seatsPerRow = 4;
            const backRowSeats = 5;
            const normalSeats = totalSeats - backRowSeats;
            const normalRows = Math.ceil(normalSeats / seatsPerRow);
            
            let seatNumber = 1;
            
            // Create normal rows
            for (let row = 0; row < normalRows; row++) {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'seat-row';
                
                // Add row label
                const label = document.createElement('div');
                label.className = 'seat-row-label';
                label.textContent = String.fromCharCode(65 + row);
                rowDiv.appendChild(label);
                
                // Left seats
                for (let i = 0; i < 2 && seatNumber <= normalSeats; i++) {
                    rowDiv.appendChild(createSeat(seatNumber++));
                }
                
                // Aisle
                const aisle = document.createElement('div');
                aisle.className = 'aisle';
                rowDiv.appendChild(aisle);
                
                // Right seats
                for (let i = 0; i < 2 && seatNumber <= normalSeats; i++) {
                    rowDiv.appendChild(createSeat(seatNumber++));
                }
                
                container.appendChild(rowDiv);
            }
            
            // Create back row
            if (backRowSeats > 0) {
                const backRow = document.createElement('div');
                backRow.className = 'seat-row mt-3';
                
                const label = document.createElement('div');
                label.className = 'seat-row-label';
                label.textContent = String.fromCharCode(65 + normalRows);
                backRow.appendChild(label);
                
                for (let i = 0; i < backRowSeats; i++) {
                    backRow.appendChild(createSeat(seatNumber++));
                }
                
                container.appendChild(backRow);
            }
        }

        function createSeat(seatNumber) {
            const seat = document.createElement('div');
            seat.className = 'seat';
            seat.textContent = seatNumber;
            seat.dataset.seatNumber = seatNumber;
            
            // Add tooltip
            seat.setAttribute('title', `Seat ${seatNumber}`);
            seat.setAttribute('data-bs-toggle', 'tooltip');
            seat.setAttribute('data-bs-placement', 'top');
            
            updateSeatStatus(seat, seatNumber);
            
            seat.addEventListener('click', function() {
                handleSeatClick(seatNumber);
            });
            
            // Add hover effect for available seats
            seat.addEventListener('mouseenter', function() {
                if (this.classList.contains('available')) {
                    this.style.transform = 'translateY(-2px) scale(1.05)';
                    this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.3)';
                }
            });
            
            seat.addEventListener('mouseleave', function() {
                if (this.classList.contains('available')) {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                }
            });
            
            return seat;
        }

        function updateSeatStatus(seatElement, seatNumber) {
            seatElement.className = 'seat';
            
            if (bookedSeats.includes(seatNumber)) {
                seatElement.classList.add('booked');
                seatElement.style.cursor = 'not-allowed';
            } else if (selectedSeats.includes(seatNumber)) {
                seatElement.classList.add('selected');
            } else {
                seatElement.classList.add('available');
            }
        }

        function handleSeatClick(seatNumber) {
            if (bookedSeats.includes(seatNumber)) {
                return; // Can't select booked seats
            }
            
            if (selectedSeats.includes(seatNumber)) {
                // Unselect seat
                selectedSeats = selectedSeats.filter(seat => seat !== seatNumber);
                
                // Remove from passenger
                passengers.forEach(passenger => {
                    if (passenger.seatNumber === seatNumber) {
                        passenger.seatNumber = null;
                        document.getElementById(`seat-display-${passenger.id}`).textContent = 'Not selected';
                    }
                });
            } else {
                // Select seat
                // Check if we can select more seats
                if (selectedSeats.length >= passengers.length) {
                    alert('You have already selected seats for all passengers. Remove a seat selection first or add more passengers.');
                    return;
                }
                
                // Find passenger without seat or show selection modal
                const passengerWithoutSeat = passengers.find(p => !p.seatNumber);
                if (passengerWithoutSeat) {
                    assignSeatToPassenger(seatNumber, passengerWithoutSeat.id);
                } else if (passengers.length === 1) {
                    // For single passenger, directly assign
                    assignSeatToPassenger(seatNumber, passengers[0].id);
                } else {
                    // Multiple passengers - show selection modal
                    showPassengerSelectionModal(seatNumber);
                }
            }
            
            updateSeatMap();
            updatePassengersSummary();
            checkBookingReadiness();
        }

        function assignSeatToPassenger(seatNumber, passengerId) {
            // Remove any existing seat assignment for this passenger
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger && passenger.seatNumber) {
                selectedSeats = selectedSeats.filter(seat => seat !== passenger.seatNumber);
            }
            
            // Assign new seat
            selectedSeats.push(seatNumber);
            passenger.seatNumber = seatNumber;
            document.getElementById(`seat-display-${passengerId}`).textContent = `Seat ${seatNumber}`;
            
            // Highlight the passenger card temporarily
            const passengerCard = document.querySelector(`[data-passenger-id="${passengerId}"]`);
            if (passengerCard) {
                passengerCard.style.boxShadow = '0 0 15px rgba(40, 167, 69, 0.5)';
                setTimeout(() => {
                    passengerCard.style.boxShadow = '';
                }, 2000);
            }
        }

        function showPassengerSelectionModal(seatNumber) {
            const availablePassengers = passengers.filter(p => !p.seatNumber);
            
            if (availablePassengers.length === 0) {
                alert('All passengers already have seats assigned. Please unselect a seat first to reassign.');
                return;
            }
            
            // Create modal HTML
            const modalHTML = `
                <div class="modal fade" id="seatSelectionModal" tabindex="-1" aria-labelledby="seatSelectionModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="seatSelectionModalLabel">
                                    <i class="fas fa-chair me-2"></i>Assign Seat ${seatNumber}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Which passenger should be assigned to Seat ${seatNumber}?</p>
                                <div class="passenger-selection-list">
                                    ${availablePassengers.map(passenger => `
                                        <div class="passenger-option p-3 border rounded mb-2 cursor-pointer" 
                                             onclick="selectPassengerForSeat(${seatNumber}, ${passenger.id})" 
                                             style="cursor: pointer; transition: all 0.3s;">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <i class="fas fa-user-circle fa-2x text-primary"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-1">${passenger.name || `Passenger ${passenger.id + 1}`}</h6>
                                                    <small class="text-muted">
                                                        ${passenger.discountType !== 'regular' ? `${passenger.discountType.toUpperCase()} discount` : 'Regular fare'}
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if any
            const existingModal = document.getElementById('seatSelectionModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Add hover effects
            document.querySelectorAll('.passenger-option').forEach(option => {
                option.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                    this.style.borderColor = '#007bff';
                });
                option.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                    this.style.borderColor = '';
                });
            });
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('seatSelectionModal'));
            modal.show();
        }

        function selectPassengerForSeat(seatNumber, passengerId) {
            assignSeatToPassenger(seatNumber, passengerId);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('seatSelectionModal'));
            if (modal) {
                modal.hide();
            }
            
            updateSeatMap();
            updatePassengersSummary();
            checkBookingReadiness();
        }

        function openSeatSelector(passengerId) {
            if (!selectedBusData) {
                alert('Please select a bus first before choosing seats.');
                return;
            }
            
            // Scroll to seat map and highlight it
            const seatMapCard = document.getElementById('seat-map-card');
            if (seatMapCard.style.display === 'none') {
                seatMapCard.style.display = 'block';
            }
            
            seatMapCard.scrollIntoView({ behavior: 'smooth' });
            
            // Highlight the seat map temporarily
            seatMapCard.style.boxShadow = '0 0 20px rgba(0, 123, 255, 0.5)';
            setTimeout(() => {
                seatMapCard.style.boxShadow = '';
            }, 3000);
            
            // Show instruction for this specific passenger
            const passenger = passengers.find(p => p.id === passengerId);
            const passengerName = passenger ? (passenger.name || `Passenger ${passengerId + 1}`) : `Passenger ${passengerId + 1}`;
            
            showSeatSelectionInstruction(passengerName);
        }

        function showSeatSelectionInstruction(passengerName) {
            // Remove any existing instruction
            const existingInstruction = document.querySelector('.seat-selection-instruction');
            if (existingInstruction) {
                existingInstruction.remove();
            }
            
            // Create new instruction
            const instructionHTML = `
                <div class="alert alert-info seat-selection-instruction" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-hand-pointer fa-2x text-info"></i>
                        </div>
                        <div>
                            <h6 class="alert-heading mb-1">Select Seat for ${passengerName}</h6>
                            <p class="mb-0">Click on any <span class="badge bg-success">green seat</span> below to assign it to this passenger. 
                            You can change seat assignments by clicking on different available seats.</p>
                        </div>
                    </div>
                </div>
            `;
            
            // Insert before seat map
            const seatMapContainer = document.querySelector('.seat-map-container');
            seatMapContainer.insertAdjacentHTML('beforebegin', instructionHTML);
            
            // Auto-remove instruction after 10 seconds
            setTimeout(() => {
                const instruction = document.querySelector('.seat-selection-instruction');
                if (instruction) {
                    instruction.remove();
                }
            }, 10000);
        }

        function updateSeatMap() {
            document.querySelectorAll('.seat').forEach(seat => {
                const seatNumber = parseInt(seat.dataset.seatNumber);
                updateSeatStatus(seat, seatNumber);
            });
        }

        function updateSeatMapInstructions() {
            const selectedCount = selectedSeats.length;
            const totalPassengers = passengers.length;
            const remainingCount = totalPassengers - selectedCount;
            
            if (remainingCount > 0) {
                const instruction = document.querySelector('.alert-info');
                if (instruction) {
                    instruction.innerHTML = `
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Please select ${remainingCount} more seat${remainingCount > 1 ? 's' : ''}</strong> for your remaining passenger${remainingCount > 1 ? 's' : ''}. 
                        Click on available (green) seats to assign them.
                    `;
                }
            }
        }

        function selectPaymentMethod(option) {
            // Remove selection from all options
            document.querySelectorAll('.payment-method-option').forEach(opt => {
                opt.classList.remove('selected');
                opt.querySelector('.payment-instructions').style.display = 'none';
                // Uncheck radio buttons
                const radio = opt.querySelector('input[type="radio"]');
                if (radio) radio.checked = false;
            });
            
            // Select this option
            option.classList.add('selected');
            option.querySelector('.payment-instructions').style.display = 'block';
            
            // Check the radio button
            const radio = option.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            
            const paymentMethod = option.dataset.payment;
            document.getElementById('summary_payment_method').value = paymentMethod;
            
            // Update summary display
            updatePaymentSummary(paymentMethod);
            
            // Update total amounts in payment instructions
            const totalAmount = calculateTotalAmount();
            const gcashTotal = document.getElementById('gcash-total');
            const paymayaTotal = document.getElementById('paymaya-total');
            if (gcashTotal) gcashTotal.textContent = totalAmount.toFixed(2);
            if (paymayaTotal) paymayaTotal.textContent = totalAmount.toFixed(2);
            
            checkBookingReadiness();
        }

        function updatePaymentSummary(paymentMethod) {
            const summaryContainer = document.getElementById('summary_payment_method_display');
            if (!summaryContainer) {
                // Create the payment method display in summary if it doesn't exist
                const summarySection = document.querySelector('.booking-summary');
                const paymentDiv = document.createElement('div');
                paymentDiv.className = 'mb-3';
                paymentDiv.innerHTML = `
                    <label class="form-label fw-bold">Payment Method</label>
                    <div class="form-control bg-light" id="summary_payment_method_display">Not selected</div>
                `;
                // Insert before the fare breakdown
                const fareBreakdown = summarySection.querySelector('.fare-breakdown');
                fareBreakdown.parentNode.insertBefore(paymentDiv, fareBreakdown);
            }
            
            const displayElement = document.getElementById('summary_payment_method_display');
            if (displayElement) {
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
                displayElement.innerHTML = paymentText;
            }
        }

        function updateBusSummary() {
            if (!selectedBusData) return;
            
            document.getElementById('summary_bus_id').value = selectedBusData.id;
            document.getElementById('summary_bus_info').innerHTML = `
                ${selectedBusData.type === 'Aircondition' ? 'Aircon Bus' : 'Regular Bus'}<br>
                <small class="text-muted">${selectedBusData.departure} - ${selectedBusData.arrival}</small><br>
                <small class="text-muted">Trip: ${selectedBusData.tripNumber}</small>
            `;
        }

        function updatePassengersSummary() {
            const summaryContainer = document.getElementById('summary_passengers');
            const inputContainer = document.getElementById('passengers-input-container');
            
            // Clear previous inputs
            inputContainer.innerHTML = '';
            
            if (passengers.length === 0) {
                summaryContainer.innerHTML = '<em class="text-muted">No passengers added</em>';
                return;
            }
            
            let summaryHTML = '';
            passengers.forEach((passenger, index) => {
                const seatText = passenger.seatNumber ? `Seat ${passenger.seatNumber}` : 'No seat';
                const discountText = passenger.discountType !== 'regular' ? 
                    ` (${passenger.discountType.toUpperCase()} discount)` : '';
                
                summaryHTML += `
                    <div class="d-flex justify-content-between align-items-center py-1 ${index < passengers.length - 1 ? 'border-bottom' : ''}">
                        <div>
                            <strong>${passenger.name || `Passenger ${index + 1}`}</strong><br>
                            <small class="text-muted">${seatText}${discountText}</small>
                        </div>
                    </div>
                `;
                
                // Add hidden inputs for form submission
                inputContainer.innerHTML += `
                    <input type="hidden" name="passengers[${index}][name]" value="${passenger.name}">
                    <input type="hidden" name="passengers[${index}][seat_number]" value="${passenger.seatNumber || ''}">
                    <input type="hidden" name="passengers[${index}][discount_type]" value="${passenger.discountType}">
                `;
            });
            
            summaryContainer.innerHTML = summaryHTML;
        }

        function updateFareCalculations() {
            if (!selectedBusData) return;
            
            let totalAmount = 0;
            let fareDetailsHTML = '';
            
            passengers.forEach(passenger => {
                let fare = baseFare;
                let discountAmount = 0;
                
                // Apply discount
                if (passenger.discountType !== 'regular') {
                    discountAmount = fare * 0.2; // 20% discount
                    fare = fare * 0.8;
                }
                
                totalAmount += fare;
                
                // Update individual passenger fare display
                const baseFareElement = document.getElementById(`base-fare-${passenger.id}`);
                const finalFareElement = document.getElementById(`final-fare-${passenger.id}`);
                
                if (baseFareElement) baseFareElement.textContent = `₱${baseFare.toFixed(2)}`;
                if (finalFareElement) finalFareElement.textContent = `₱${fare.toFixed(2)}`;
                
                // Add to fare breakdown
                const passengerName = passenger.name || `Passenger ${passenger.id + 1}`;
                const discountText = discountAmount > 0 ? ` (-₱${discountAmount.toFixed(2)})` : '';
                
                fareDetailsHTML += `
                    <div class="fare-item">
                        <span>${passengerName}${discountText}</span>
                        <span>₱${fare.toFixed(2)}</span>
                    </div>
                `;
            });
            
            // Add total
            fareDetailsHTML += `
                <div class="fare-item">
                    <span>Total Amount</span>
                    <span>₱${totalAmount.toFixed(2)}</span>
                </div>
            `;
            
            document.getElementById('fare-details').innerHTML = fareDetailsHTML;
            document.getElementById('total-amount').textContent = totalAmount.toFixed(2);
            
            // Update payment method amounts
            const gcashAmounts = document.querySelectorAll('.gcash-amount');
            const paymayaAmounts = document.querySelectorAll('.paymaya-amount');
            const gcashTotal = document.getElementById('gcash-total');
            const paymayaTotal = document.getElementById('paymaya-total');
            
            gcashAmounts.forEach(el => el.textContent = totalAmount.toFixed(2));
            paymayaAmounts.forEach(el => el.textContent = totalAmount.toFixed(2));
            if (gcashTotal) gcashTotal.textContent = totalAmount.toFixed(2);
            if (paymayaTotal) paymayaTotal.textContent = totalAmount.toFixed(2);
        }

        function calculateTotalAmount() {
            let total = 0;
            passengers.forEach(passenger => {
                let fare = baseFare;
                if (passenger.discountType !== 'regular') {
                    fare = fare * 0.8; // 20% discount
                }
                total += fare;
            });
            return total;
        }

        function checkBookingReadiness() {
            const hasValidPassengers = passengers.length > 0 && passengers.every(p => p.name && p.seatNumber);
            const hasPaymentMethod = document.getElementById('summary_payment_method').value;
            const hasRequiredDocuments = passengers.every(p => {
                if (p.discountType !== 'regular') {
                    return p.discountIdFile !== null;
                }
                return true;
            });
            
            // Show payment selection when passengers have seats
            if (hasValidPassengers) {
                document.getElementById('payment-selection').style.display = 'block';
                document.getElementById('payment-selection').scrollIntoView({ behavior: 'smooth' });
            }
            
            const isReady = hasValidPassengers && hasPaymentMethod && hasRequiredDocuments;
            document.getElementById('confirmBookingBtn').disabled = !isReady;
        }

        function handleFormSubmission(e) {
            e.preventDefault();
            
            // Validate all requirements
            const errors = [];
            
            if (!selectedBusData) {
                errors.push('Please select a bus');
            }
            
            if (passengers.length === 0) {
                errors.push('Please add at least one passenger');
            }
            
            passengers.forEach((passenger, index) => {
                if (!passenger.name) {
                    errors.push(`Please enter name for Passenger ${index + 1}`);
                }
                if (!passenger.seatNumber) {
                    errors.push(`Please select a seat for Passenger ${index + 1}`);
                }
                if (passenger.discountType !== 'regular' && !passenger.discountIdFile) {
                    errors.push(`Please upload ID proof for Passenger ${index + 1}`);
                }
            });
            
            const paymentMethod = document.getElementById('summary_payment_method').value;
            if (!paymentMethod) {
                errors.push('Please select a payment method');
            }
            
            if ((paymentMethod === 'gcash' || paymentMethod === 'paymaya')) {
                const proofInput = document.getElementById('payment_proof') || document.getElementById('payment_proof_paymaya');
                if (!proofInput || !proofInput.files.length) {
                    errors.push('Please upload payment proof');
                }
            }
            
            if (errors.length > 0) {
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return;
            }
            
            // Create FormData for file uploads
            const formData = new FormData();
            
            // Add form data
            formData.append('bus_id', selectedBusData.id);
            formData.append('booking_date', document.getElementById('date').value);
            formData.append('payment_method', paymentMethod);
            formData.append('book_tickets', '1');
            
            // Add passengers data
            passengers.forEach((passenger, index) => {
                formData.append(`passengers[${index}][name]`, passenger.name);
                formData.append(`passengers[${index}][seat_number]`, passenger.seatNumber);
                formData.append(`passengers[${index}][discount_type]`, passenger.discountType);
                
                // Add discount ID files
                if (passenger.discountIdFile) {
                    formData.append(`discount_id_proof_${index}`, passenger.discountIdFile);
                }
            });
            
            // Add payment proof
            if (paymentMethod === 'gcash' || paymentMethod === 'paymaya') {
                const proofInput = document.getElementById('payment_proof') || document.getElementById('payment_proof_paymaya');
                if (proofInput && proofInput.files.length > 0) {
                    formData.append('payment_proof', proofInput.files[0]);
                }
            }
            
            // Show loading
            const submitBtn = document.getElementById('confirmBookingBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            
            // Submit form
            fetch('booking.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(data => {
                if (data) {
                    // Handle response (could be error message)
                    document.body.innerHTML = data;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while processing your booking. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-ticket-alt me-2"></i>Confirm Booking';
            });
        }

        // Update date in summary when changed
        document.getElementById('date').addEventListener('change', function() {
            const date = new Date(this.value);
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('summary_date').textContent = date.toLocaleDateString('en-US', options);
            document.getElementById('summary_booking_date').value = this.value;
        });
    </script>
</body>
</html>      