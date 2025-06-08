<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_tickets'])) {
    $bus_id = isset($_POST['bus_id']) ? intval($_POST['bus_id']) : 0;
    $passenger_count = isset($_POST['passenger_count']) ? intval($_POST['passenger_count']) : 1;
    $booking_date = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
    
    // Debug: Log all received POST data
    error_log("=== RECEIVED POST DATA ===");
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'discount_type') !== false || strpos($key, 'passenger_name') !== false || strpos($key, 'seat_number') !== false) {
            error_log("$key: $value");
        }
    }
    error_log("=== END POST DATA ===");
    
    // Validation
    $errors = [];
    if ($bus_id <= 0) {
        $errors[] = "Please select a valid bus";
    }
    if ($passenger_count <= 0 || $passenger_count > 10) {
        $errors[] = "Invalid passenger count. Maximum 10 passengers allowed.";
    }
    if (empty($booking_date)) {
        $errors[] = "Please select a travel date";
    }
    if (empty($payment_method)) {
        $errors[] = "Please select a payment method";
    }
    
    // Enhanced passenger details validation
    $passengers = [];
    for ($i = 1; $i <= $passenger_count; $i++) {
        // Get discount type for THIS SPECIFIC passenger
        $discount_type_key = "discount_type_$i";
        $passenger_discount = isset($_POST[$discount_type_key]) ? $_POST[$discount_type_key] : 'regular';
        
        $passenger = [
            'name' => isset($_POST["passenger_name_$i"]) ? trim($_POST["passenger_name_$i"]) : '',
            'seat_number' => isset($_POST["seat_number_$i"]) ? intval($_POST["seat_number_$i"]) : 0,
            'discount_type' => $passenger_discount, 
            'discount_id_proof' => null
        ];
        
        // Enhanced debug logging to track each passenger's discount type
        error_log("=== PASSENGER $i PROCESSING ===");
        error_log("Raw POST key '$discount_type_key': " . (isset($_POST[$discount_type_key]) ? $_POST[$discount_type_key] : 'NOT SET'));
        error_log("Processed: Name='{$passenger['name']}', Seat={$passenger['seat_number']}, Discount='{$passenger['discount_type']}'");
        error_log("=== END PASSENGER $i ===");
        
        if (empty($passenger['name'])) {
            $errors[] = "Please enter name for passenger $i";
        }
        if ($passenger['seat_number'] <= 0) {
            $errors[] = "Please select a seat for passenger $i";
        }
        
        // Check for duplicate seat selection within this booking
        for ($j = 0; $j < count($passengers); $j++) {
            if ($passengers[$j]['seat_number'] == $passenger['seat_number']) {
                $errors[] = "Passenger $i has selected the same seat as passenger " . ($j + 1) . ". Please select different seats.";
            }
        }
        
        $passengers[] = $passenger;
    }
    
    
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            
            $group_reference = 'GRP-' . date('Ymd') . '-' . uniqid();

            // Get trip number and fare for this bus
            $trip_number = null;
            $base_fare = null;
            $trip_query = "SELECT s.trip_number, r.fare 
                        FROM schedules s 
                        JOIN buses b ON s.bus_id = b.id 
                        JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
                        WHERE s.bus_id = ? LIMIT 1";
            $trip_stmt = $conn->prepare($trip_query);
            $trip_stmt->bind_param("i", $bus_id);
            $trip_stmt->execute();
            $trip_result = $trip_stmt->get_result();
            if ($trip_result && $trip_result->num_rows > 0) {
                $trip_data = $trip_result->fetch_assoc();
                $trip_number = $trip_data['trip_number'];
                $base_fare = $trip_data['fare'];
            }

            // Set payment status based on payment method
            $payment_status = ($payment_method === 'counter') ? 'pending' : 
                            (($payment_method === 'gcash' || $payment_method === 'paymaya') ? 'awaiting_verification' : 'pending');

            $payment_proof_status = ($payment_method === 'counter') ? 'not_required' : 
                                ($payment_proof_path ? 'uploaded' : 'pending');

            $current_timestamp = date('Y-m-d H:i:s');
            $booking_ids = [];

            // Enhanced booking insertion with proper discount handling - FIXED VERSION
            foreach ($passengers as $index => $passenger) {
                // Generate individual booking reference
                $individual_reference = 'BK-' . date('Ymd') . '-' . substr($group_reference, 4) . '-P' . ($index + 1);
                
                // Calculate fare based on THIS SPECIFIC passenger's discount type - FIXED
                $passenger_discount_type = $passenger['discount_type']; 
                $discount_amount = 0;
                $final_fare = $base_fare;
                
                // Apply discount ONLY if this specific passenger has a discount
                if ($passenger_discount_type !== 'regular' && $base_fare) {
                    if (in_array($passenger_discount_type, ['student', 'senior', 'pwd'])) {
                        $discount_amount = $base_fare * 0.2; // 20% discount
                        $final_fare = $base_fare * 0.8;
                    }
                }
                
                // Enhanced debug logging for each passenger's fare calculation
                error_log("=== FARE CALCULATION FOR PASSENGER " . ($index + 1) . " ===");
                error_log("Passenger Name: {$passenger['name']}");
                error_log("Discount Type: {$passenger_discount_type}");
                error_log("Base Fare: {$base_fare}");
                error_log("Discount Amount: {$discount_amount}");
                error_log("Final Fare: {$final_fare}");
                error_log("=== END FARE CALCULATION ===");
                
                $passenger_name = $passenger['name'];
                
                $insert_query = "INSERT INTO bookings (
                    bus_id, user_id, seat_number, passenger_name, booking_date, 
                    booking_status, group_booking_id, booking_reference, trip_number, 
                    payment_method, payment_status, payment_proof, payment_proof_status, 
                    payment_proof_timestamp, discount_type, discount_id_proof, discount_verified,
                    base_fare, discount_amount, final_fare
                ) VALUES (?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
            
                $insert_stmt = $conn->prepare($insert_query);
                
                if (!$insert_stmt) {
                    throw new Exception("Failed to prepare insert statement for passenger " . ($index + 1) . ": " . $conn->error);
                }
                
                // Enhanced parameter binding with additional debug
                error_log("=== BINDING PARAMETERS FOR PASSENGER " . ($index + 1) . " ===");
                error_log("Discount type being bound: '$passenger_discount_type'");
                
                $insert_stmt->bind_param("iiississsssssssddd", 
                    $bus_id,                           
                    $user_id,                         
                    $passenger['seat_number'],         
                    $passenger_name,                  
                    $booking_date,                    
                    $group_reference,                  
                    $individual_reference,            
                    $trip_number,                     
                    $payment_method,                  
                    $payment_status,                  
                    $payment_proof_path,              
                    $payment_proof_status,            
                    $current_timestamp,               
                    $passenger_discount_type,        
                    $passenger['discount_id_proof'],   
                    $base_fare,                        
                    $discount_amount,                  
                    $final_fare                        
                );
                
                // Execute the statement and check for success
                if (!$insert_stmt->execute()) {
                    throw new Exception("Error creating booking for passenger " . ($index + 1) . ": " . $insert_stmt->error);
                }
                
                // Get the insert ID and verify the insertion
                $last_insert_id = $conn->insert_id;
                if ($last_insert_id > 0) {
                    $booking_ids[] = $last_insert_id;
                    
                    // Enhanced verification: Check what was actually inserted
                    $verify_query = "SELECT id, passenger_name, discount_type, base_fare, discount_amount, final_fare FROM bookings WHERE id = ?";
                    $verify_stmt = $conn->prepare($verify_query);
                    if ($verify_stmt) {
                        $verify_stmt->bind_param("i", $last_insert_id);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        if ($verify_row = $verify_result->fetch_assoc()) {
                            error_log("=== VERIFICATION: SUCCESSFULLY INSERTED ===");
                            error_log("ID: {$verify_row['id']}");
                            error_log("Name: {$verify_row['passenger_name']}");
                            error_log("Discount Type: {$verify_row['discount_type']}");
                            error_log("Base Fare: {$verify_row['base_fare']}");
                            error_log("Discount Amount: {$verify_row['discount_amount']}");
                            error_log("Final Fare: {$verify_row['final_fare']}");
                            error_log("=== END VERIFICATION ===");
                        }
                        $verify_stmt->close();
                    }
                } else {
                    throw new Exception("Failed to get insert ID for passenger " . ($index + 1));
                }
                
                // Close the prepared statement
                $insert_stmt->close();
            }
            
            // Final verification: Check all bookings in the group
            error_log("=== FINAL GROUP VERIFICATION ===");
            error_log("Group Reference: " . $group_reference);
            error_log("Booking IDs: " . implode(',', $booking_ids));
            
            $verify_all_query = "SELECT id, booking_reference, group_booking_id, passenger_name, seat_number, discount_type, final_fare FROM bookings WHERE group_booking_id = ?";
            $verify_all_stmt = $conn->prepare($verify_all_query);
            if ($verify_all_stmt) {
                $verify_all_stmt->bind_param("s", $group_reference);
                $verify_all_stmt->execute();
                $verify_all_result = $verify_all_stmt->get_result();
                
                error_log("Found " . $verify_all_result->num_rows . " bookings for group: " . $group_reference);
                while ($verify_row = $verify_all_result->fetch_assoc()) {
                    error_log("Booking ID: {$verify_row['id']}, Reference: {$verify_row['booking_reference']}, Passenger: {$verify_row['passenger_name']}, Seat: {$verify_row['seat_number']}, Discount: {$verify_row['discount_type']}, Fare: {$verify_row['final_fare']}");
                }
                $verify_all_stmt->close();
            }
            error_log("=== END FINAL VERIFICATION ===");
            
            // Commit the transaction
            $conn->commit();
            
            $booking_success = true;
            $booking_reference = $group_reference;
            
            // Redirect to receipt page
            $redirect_params = [
                'group_booking_id=' . urlencode($group_reference),
                'booking_ids=' . urlencode(implode(',', $booking_ids))
            ];
            
            $redirect_url = "auth/booking_receipt.php?" . implode('&', $redirect_params);
            error_log("Redirecting to: " . $redirect_url);
            
            header("Location: " . $redirect_url);
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $booking_error = "Database error: " . $e->getMessage();
            error_log("Booking error: " . $e->getMessage());
        }
    } else {
        $booking_error = implode(", ", $errors);
        error_log("Validation errors: " . $booking_error);
    }
}

/**
 * Process payment proof image upload
 */
function processPaymentProofUpload($payment_method) {
    try {
        if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $file = $_FILES['payment_proof'];
        $upload_dir = __DIR__ . '/../../uploads/payment_proofs/';
        
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            return null;
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }
        
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $payment_method . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
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
 */
function processDiscountIDUpload($discount_type, $passenger_index) {
    try {
        $file_key = "discount_id_proof_$passenger_index";
        
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $file = $_FILES[$file_key];
        $upload_dir = __DIR__ . '/../../uploads/discount_ids/';
        
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_info = getimagesize($file['tmp_name']);
        if ($file_info === false || !in_array($file_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
            return null;
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }
        
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_filename = $discount_type . '_p' . $passenger_index . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_filename;
        
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            return 'uploads/discount_ids/' . $unique_filename;
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error processing discount ID upload: " . $e->getMessage());
        return null;
    }
}

// Fetch all destinations from routes table for dropdowns
$locations = [];
try {
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

// Fetch all buses for display
$all_buses = [];
try {
    $all_buses_query = "SELECT b.*, 
                        (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND booking_status = 'confirmed') as active_bookings
                        FROM buses b 
                        ORDER BY b.status, b.id";
    
    $all_buses_result = $conn->query($all_buses_query);
    
    if ($all_buses_result) {
        while ($row = $all_buses_result->fetch_assoc()) {
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
        
        if ($selected_bus_id > 0) {
            $buses_query .= " AND b.id = ?";
        }
        
        $buses_query .= " ORDER BY b.id, s.departure_time";
        
        $buses_stmt = $conn->prepare($buses_query);
        $route_pattern = '%' . $selected_origin . ' → ' . $selected_destination . '%';
        
        if ($selected_bus_id > 0) {
            $buses_stmt->bind_param("sssi", $selected_date, $route_pattern, $selected_date, $selected_bus_id);
        } else {
            $buses_stmt->bind_param("sss", $selected_date, $route_pattern, $selected_date);
        }
        
        if ($buses_stmt->execute()) {
            $buses_result = $buses_stmt->get_result();
            
            while ($row = $buses_result->fetch_assoc()) {
                $row['departure_time'] = date('h:i A', strtotime($row['departure_time']));
                $row['arrival_time'] = date('h:i A', strtotime($row['arrival_time']));
                $row['available_seats'] = $row['seat_capacity'] - $row['booked_seats'];
                
                $route_parts = explode(' → ', $row['route_name']);
                $row['origin'] = $route_parts[0] ?? $selected_origin;
                $row['destination'] = $route_parts[1] ?? $selected_destination;
                $row['id'] = $row['bus_id'];
                
                if (empty($row['trip_number'])) {
                    $row['trip_number'] = 'Trip';
                }
                
                $available_buses[] = $row;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error fetching buses: " . $e->getMessage());
    }
}

// Fetch user data
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
        .passenger-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .passenger-card.active {
            border-color: #007bff;
            box-shadow: 0 0 10px rgba(0,123,255,0.3);
        }
        
        .passenger-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            margin: -1px -1px 20px -1px;
        }
        
        .discount-selector {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
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
        
        .seat.available {
            background-color: #28a745;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .seat.available:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .seat.booked {
            background-color: #dc3545;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .seat.selected {
            background-color: #007bff;
            transform: scale(1.1);
            box-shadow: 0 0 10px rgba(0,123,255,0.6);
            border-color: #fff;
        }
        
        .seat.passenger-1 { background-color: #007bff; }
        .seat.passenger-2 { background-color: #007bff; }
        .seat.passenger-3 { background-color: #007bff; }
        .seat.passenger-4 { background-color: #007bff; }
        .seat.passenger-5 { background-color: #007bff; }
        .seat.passenger-6 { background-color: #007bff; }
        .seat.passenger-7 { background-color: #007bff; }
        .seat.passenger-8 { background-color: #007bff; }
        .seat.passenger-9 { background-color: #007bff; }
        .seat.passenger-10 { background-color: #007bff; }
        
        .seat-map-container {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.1);
        }
        
        .seat-row {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
            gap: 10px;
            align-items: center;
        }
        
        .passenger-counter {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: bold;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        }
        
        .passenger-counter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,123,255,0.4);
        }
        
        .passenger-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .discount-upload-section {
            background-color: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            transition: all 0.3s ease;
        }
        
        .discount-upload-section.required {
            background-color: #fff3cd;
            border-color: #ffeaa7;
        }
        
        .summary-card {
            position: sticky;
            top: 20px;
            max-height: calc(100vh - 40px);
            overflow-y: auto;
        }
        
        .passenger-summary {
            border-left: 4px solid #007bff;
            padding-left: 15px;
            margin-bottom: 15px;
            background-color: #f8f9fa;
            border-radius: 0 8px 8px 0;
            padding: 15px;
        }
        
        .fare-breakdown {
            background-color: #e7f3ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .total-amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: #007bff;
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #e7f3ff, #cce7ff);
            border-radius: 8px;
            margin-top: 15px;
        }
        .qr-code-section {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-top: 15px;
        }
        .qr-placeholder {
            width: 200px;
            height: 200px;
            background: #e9ecef;
            border: 2px solid #adb5bd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 48px;
            color: #6c757d;
        }
        .payment-method-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .payment-method-card:hover {
            background-color: #f8f9fa;
            border-color: #0d6efd !important;
        }
        .payment-method-card.selected {
            background-color: #e7f3ff;
            border-color: #0d6efd !important;
            border-width: 2px !important;
        }
        .qr-instructions {
            font-size: 14px;
            color: #6c757d;
            line-height: 1.5;
        }

        @media (max-width: 991.98px) {
            .summary-card {
                position: relative;
                top: auto;
                max-height: none;
                margin-top: 20px;
            }
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
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
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Booking Successful!</h4>
                    <p>Your tickets have been booked successfully. Your group booking reference is: <strong><?php echo $booking_reference; ?></strong></p>
                    <hr>
                    <p class="mb-0">You can view your booking details in <a href="mybookings.php" class="alert-link">My Bookings</a> page.</p>
                </div>
                <?php elseif (!empty($booking_error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $booking_error; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- LEFT COLUMN - Main Content -->
            <div class="col-lg-8">
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
                            <?php foreach ($available_buses as $bus): ?>
                            <div class="bus-card p-3 border rounded mb-3" 
                                data-bus-id="<?php echo $bus['id']; ?>" 
                                data-fare="<?php echo $bus['fare_amount']; ?>" 
                                data-capacity="<?php echo $bus['seat_capacity']; ?>"
                                style="cursor: pointer; transition: all 0.3s;">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="mb-1"><?php echo htmlspecialchars($bus['origin']); ?> to <?php echo htmlspecialchars($bus['destination']); ?></h5>
                                        <p class="mb-0">
                                            <i class="fas fa-clock me-1"></i><?php echo $bus['departure_time']; ?> - <?php echo $bus['arrival_time']; ?>
                                            <span class="badge bg-info ms-2"><?php echo $bus['trip_number']; ?></span>
                                        </p>
                                        <small class="text-muted">
                                            <?php echo $bus['bus_type']; ?> • <?php echo $bus['seat_capacity']; ?> Seats • 
                                            <?php echo $bus['available_seats']; ?> Available
                                        </small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <h4 class="text-primary mb-0">₱<?php echo number_format($bus['fare_amount'], 2); ?></h4>
                                        <small class="text-muted">per person</small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No buses available for the selected route and date.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="booking-section" style="display: none;">
                    <!-- Passenger Count Selection -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Number of Passengers</h5>
                        </div>
                        <div class="card-body text-center">
                            <p class="mb-3">How many passengers will be traveling?</p>
                            <div class="d-flex justify-content-center align-items-center gap-3">
                                <button type="button" class="btn btn-outline-primary" id="decreasePassengers">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <div class="passenger-counter" id="passengerCount">1</div>
                                <button type="button" class="btn btn-outline-primary" id="increasePassengers">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            <small class="text-muted mt-2 d-block">Maximum 10 passengers per booking</small>
                        </div>
                    </div>

                    <!-- Seat Selection -->
                    <div class="card mb-4" id="seat-selection">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-chair me-2"></i>Select Seats</h5>
                        </div>
                        <div class="card-body">
                            <div class="passenger-legend">
                                <div class="legend-item">
                                    <div class="seat available" style="width: 25px; height: 25px;"></div>
                                    <span>Available</span>
                                </div>
                                <div class="legend-item">
                                    <div class="seat booked" style="width: 25px; height: 25px;"></div>
                                    <span>Booked</span>
                                </div>
                                <div id="passenger-legend-items"></div>
                            </div>
                            
                            <div class="text-center mb-4">
                                <div class="driver-area bg-secondary text-white p-2 rounded mb-2" style="max-width: 200px; margin: 0 auto;">
                                    <i class="fas fa-steering-wheel me-1"></i> Driver Area
                                </div>
                            </div>
                            
                            <div class="seat-map-container" id="seatMapContainer">
                                <div class="text-center p-4">
                                    <p>Please select a bus first to view the seat map.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Passenger Details -->
                    <div class="card mb-4" id="passenger-details" style="display: none;">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-user-friends me-2"></i>Passenger Details</h5>
                        </div>
                        <div class="card-body">
                            <div id="passenger-forms"></div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="card mb-4" id="payment-selection">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Method</h5>
                        </div>
                        <div class="card-body">
                            <div class="payment-methods">
                                <!-- Counter Payment -->
                                <div class="form-check mb-3 p-3 border rounded payment-method-card">
                                    <input class="form-check-input" type="radio" name="payment_method" id="counter" value="counter">
                                    <label class="form-check-label w-100" for="counter">
                                        <strong><i class="fas fa-money-bill-wave me-2"></i>Pay Over the Counter</strong>
                                        <small class="d-block text-muted">Pay at the terminal before your trip</small>
                                    </label>
                                </div>

                                <!-- GCash Payment -->
                                <div class="form-check mb-3 p-3 border rounded payment-method-card">
                                    <input class="form-check-input" type="radio" name="payment_method" id="gcash" value="gcash">
                                    <label class="form-check-label w-100" for="gcash">
                                        <strong><i class="fas fa-mobile-alt me-2"></i>GCash</strong>
                                        <small class="d-block text-muted">Pay instantly using GCash (requires payment proof upload)</small>
                                    </label>
                                    
                                    <!-- GCash QR Code Section -->
                                    <div id="gcash-qr-section" class="qr-code-section" style="display: none;">
                                        <h6 class="text-primary mb-3"><i class="fas fa-qrcode me-2"></i>Scan GCash QR Code</h6>
                                        <img src="../assets/QRgcash.jpg" alt="GCash QR Code" class="img-fluid" style="max-width: 200px;">
                                        <p class="qr-instructions mb-2">
                                            <strong>Instructions:</strong><br>
                                            1. Open your GCash app<br>
                                            2. Tap "Scan QR" or "Pay QR"<br>
                                            3. Scan the QR code above<br>
                                            4. Enter the payment amount<br>
                                            5. Complete the transaction<br>
                                            6. Upload payment proof below
                                        </p>
                                    </div>
                                </div>

                                <!-- PayMaya Payment -->
                                <div class="form-check mb-3 p-3 border rounded payment-method-card">
                                    <input class="form-check-input" type="radio" name="payment_method" id="paymaya" value="paymaya">
                                    <label class="form-check-label w-100" for="paymaya">
                                        <strong><i class="fas fa-credit-card me-2"></i>PayMaya</strong>
                                        <small class="d-block text-muted">Pay using PayMaya (requires payment proof upload)</small>
                                    </label>
                                    
                                    <!-- PayMaya QR Code Section -->
                                    <div id="paymaya-qr-section" class="qr-code-section" style="display: none;">
                                        <h6 class="text-success mb-3"><i class="fas fa-qrcode me-2"></i>Scan PayMaya QR Code</h6>
                                        <img src="../assets/QRgcash.jpg" alt="GCash QR Code" class="img-fluid" style="max-width: 200px;">
                                        <p class="qr-instructions mb-2">
                                            <strong>Instructions:</strong><br>
                                            1. Open your PayMaya app<br>
                                            2. Tap "Scan to Pay"<br>
                                            3. Scan the QR code above<br>
                                            4. Enter the payment amount<br>
                                            5. Complete the transaction<br>
                                            6. Upload payment proof below
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Proof Upload Section -->
                            <div id="payment-proof-section" style="display: none;">
                                <div class="card card-body bg-light">
                                    <h6><i class="fas fa-upload me-2"></i>Upload Payment Proof</h6>
                                    <div class="mb-3">
                                        <input type="file" class="form-control" id="payment_proof" name="payment_proof" accept="image/*">
                                        <div class="form-text">Upload a screenshot of your payment confirmation (JPG, PNG, max 5MB)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN - Booking Summary -->
            <div class="col-lg-4">
                <!-- Booking Summary -->
                <div class="card summary-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Booking Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>Route:</strong>
                            <div class="text-muted" id="summary-route">
                                <?php echo htmlspecialchars($selected_origin); ?> to <?php echo htmlspecialchars($selected_destination); ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Travel Date:</strong>
                            <div class="text-muted" id="summary-date">
                                <?php echo date('F d, Y', strtotime($selected_date)); ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Bus Details:</strong>
                            <div class="text-muted" id="summary-bus">Not selected</div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Passengers:</strong>
                            <div id="passenger-summary"></div>
                        </div>
                        
                        <div class="fare-breakdown">
                            <h6><i class="fas fa-calculator me-2"></i>Fare Breakdown</h6>
                            <div id="fare-details"></div>
                            <div class="total-amount" id="total-amount">
                                Total: ₱0.00
                            </div>
                        </div>
                        
                        <!-- Booking Form -->
                        <form id="bookingForm" method="POST" enctype="multipart/form-data" style="display: none;">
                            <input type="hidden" name="bus_id" id="form_bus_id">
                            <input type="hidden" name="booking_date" value="<?php echo $selected_date; ?>">
                            <input type="hidden" name="passenger_count" id="form_passenger_count">
                            <input type="hidden" name="payment_method" id="form_payment_method">
                            
                            <div id="form-passenger-data"></div>
                            
                            <button type="submit" name="book_tickets" class="btn btn-success btn-lg w-100 mt-3">
                                <i class="fas fa-ticket-alt me-2"></i>Confirm Booking
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Constants
        const MAX_PASSENGERS = 10;
        const SESSION_TIMEOUT = 30 * 60 * 1000;
        
        // State variables
        let selectedBus = null;
        let passengerCount = 1;
        let selectedSeats = {};
        let bookedSeats = [];
        let busCapacity = 0;
        let farePerPerson = 0;
        let lastActivity = Date.now();
        
        // Cached DOM elements
        const domCache = {
            passengerCount: document.getElementById('passengerCount'),
            summaryBus: document.getElementById('summary-bus'),
            bookingSection: document.getElementById('booking-section'),
            seatMapContainer: document.getElementById('seatMapContainer'),
            passengerLegendItems: document.getElementById('passenger-legend-items'),
            passengerDetails: document.getElementById('passenger-details'),
            passengerForms: document.getElementById('passenger-forms'),
            paymentSelection: document.getElementById('payment-selection'),
            passengerSummary: document.getElementById('passenger-summary'),
            fareDetails: document.getElementById('fare-details'),
            totalAmount: document.getElementById('total-amount'),
            bookingForm: document.getElementById('bookingForm'),
            formPassengerData: document.getElementById('form-passenger-data'),
            formBusId: document.getElementById('form_bus_id'),
            formPassengerCount: document.getElementById('form_passenger_count'),
            formPaymentMethod: document.getElementById('form_payment_method')
        };
        
        // Passenger colors for seat visualization
        const passengerColors = [
            'passenger-1', 'passenger-2', 'passenger-3', 'passenger-4', 'passenger-5',
            'passenger-6', 'passenger-7', 'passenger-8', 'passenger-9', 'passenger-10'
        ];

        // Initialize the application
        function init() {
            setupEventListeners();
            updatePassengerLegend();
            startSessionTimer();
            
            // If coming from search with a specific bus selected
            <?php if ($selected_bus_id > 0): ?>
            const busCard = document.querySelector(`.bus-card[data-bus-id="<?php echo $selected_bus_id; ?>"]`);
            if (busCard) {
                busCard.click();
            }
            <?php endif; ?>
        }

        // Set up all event listeners
        function setupEventListeners() {
            // Bus selection
            document.querySelectorAll('.bus-card').forEach(card => {
                card.addEventListener('click', handleBusSelection);
                card.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        handleBusSelection.call(card, e);
                    }
                });
            });

            // Passenger count controls
            document.getElementById('increasePassengers').addEventListener('click', () => {
                updatePassengerCount(passengerCount + 1);
            });
            
            document.getElementById('decreasePassengers').addEventListener('click', () => {
                updatePassengerCount(passengerCount - 1);
            });

            // Payment method handlers
            document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
                radio.addEventListener('change', handlePaymentMethodChange);
            });

            // Form submission
            if (domCache.bookingForm) {
                domCache.bookingForm.addEventListener('submit', handleFormSubmission);
            }

            // Track user activity
            document.addEventListener('click', updateLastActivity);
            document.addEventListener('keypress', updateLastActivity);
        }

        // Update last activity timestamp
        function updateLastActivity() {
            lastActivity = Date.now();
        }

        // Start session timeout timer
        function startSessionTimer() {
            setInterval(() => {
                if (Date.now() - lastActivity > SESSION_TIMEOUT) {
                    if (confirm('Your session has expired. Would you like to start over?')) {
                        location.reload();
                    }
                }
            }, 60000); // Check every minute
        }

        // Bus selection handler
        function handleBusSelection() {
            // Remove selection from other buses
            document.querySelectorAll('.bus-card').forEach(c => {
                c.classList.remove('border-primary');
                c.setAttribute('aria-selected', 'false');
            });
            
            // Select this bus
            this.classList.add('border-primary');
            this.setAttribute('aria-selected', 'true');
            this.focus();
            
            selectedBus = {
                id: this.getAttribute('data-bus-id'),
                fare: parseFloat(this.getAttribute('data-fare')),
                capacity: parseInt(this.getAttribute('data-capacity'))
            };
            
            farePerPerson = selectedBus.fare;
            busCapacity = selectedBus.capacity;
            
            // Update summary
            domCache.summaryBus.textContent = 
                this.querySelector('h5').textContent + ' - ' + 
                this.querySelector('p').textContent;
            
            // Show booking section and load seats
            domCache.bookingSection.style.display = 'block';
            loadSeatMap();
            updateSummary();
            
            // Scroll to booking section
            domCache.bookingSection.scrollIntoView({ behavior: 'smooth' });
            
            updateLastActivity();
        }

        // Update passenger count
        function updatePassengerCount(newCount) {
            if (newCount < 1 || newCount > MAX_PASSENGERS) return;
            
            passengerCount = newCount;
            domCache.passengerCount.textContent = passengerCount;
            
            // Clear selections beyond current count
            for (let i = passengerCount + 1; i <= MAX_PASSENGERS; i++) {
                if (selectedSeats[i]) {
                    const seatElement = document.querySelector(`[data-seat-number="${selectedSeats[i]}"]`);
                    if (seatElement) {
                        seatElement.className = 'seat available';
                        seatElement.setAttribute('aria-label', `Seat ${selectedSeats[i]} - Available`);
                    }
                    delete selectedSeats[i];
                }
            }
            
            updatePassengerLegend();
            updatePassengerForms();
            updateSummary();
            updateLastActivity();
        }

        // Update passenger legend
        function updatePassengerLegend() {
            domCache.passengerLegendItems.innerHTML = '';
            
            for (let i = 1; i <= passengerCount; i++) {
                const legendItem = document.createElement('div');
                legendItem.className = 'legend-item';
                legendItem.innerHTML = `
                    <div class="seat ${passengerColors[i-1]}" 
                        style="width: 25px; height: 25px; ${passengerColors[i-1] === 'passenger-3' ? 'color: #000;' : ''}"
                        aria-label="Passenger ${i} color indicator"></div>
                    <span>Passenger ${i}</span>
                `;
                domCache.passengerLegendItems.appendChild(legendItem);
            }
        }

        // Load seat map from server
        async function loadSeatMap() {
            if (!selectedBus) return;
            
            try {
                // Show loading state
                domCache.seatMapContainer.innerHTML = '<div class="loading-spinner">Loading seat map...</div>';
                
                const response = await fetch(`../../backend/connections/get_booked_seats.php?bus_id=${selectedBus.id}&date=<?php echo $selected_date; ?>`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                bookedSeats = data.bookedSeats || [];
                
                generateSeatMap();
            } catch (error) {
                console.error('Error loading seat map:', error);
                domCache.seatMapContainer.innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load seat map. 
                        <button class="btn btn-sm btn-outline-danger" onclick="loadSeatMap()">Retry</button>
                    </div>
                `;
            }
            updateLastActivity();
        }

        // Generate seat map UI
        function generateSeatMap() {
            const container = domCache.seatMapContainer;
            container.innerHTML = '';
            
            const totalSeats = busCapacity;
            const seatsPerRow = 4;
            const backRowSeats = 6;
            const normalSeats = totalSeats - backRowSeats;
            const normalRows = Math.floor(normalSeats / seatsPerRow);
            
            let seatNumber = 1;
            
            // Generate normal rows
            for (let row = 1; row <= normalRows; row++) {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'seat-row';
                rowDiv.setAttribute('aria-label', `Row ${row}`);
                
                // Left seats
                for (let i = 0; i < 2; i++) {
                    rowDiv.appendChild(createSeat(seatNumber++));
                }
                
                // Aisle
                const aisle = document.createElement('div');
                aisle.style.width = '30px';
                aisle.setAttribute('aria-hidden', 'true');
                rowDiv.appendChild(aisle);
                
                // Right seats
                for (let i = 0; i < 2; i++) {
                    rowDiv.appendChild(createSeat(seatNumber++));
                }
                
                container.appendChild(rowDiv);
            }
            
            // Back row
            if (backRowSeats > 0) {
                const backRow = document.createElement('div');
                backRow.className = 'seat-row mt-3';
                backRow.setAttribute('aria-label', 'Back row');
                
                for (let i = 0; i < backRowSeats; i++) {
                    backRow.appendChild(createSeat(seatNumber++));
                }
                
                container.appendChild(backRow);
            }
            
            // If all seats are booked
            if (bookedSeats.length >= busCapacity) {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        This bus is fully booked. Please select another bus.
                    </div>
                `;
                domCache.passengerDetails.style.display = 'none';
            }
        }

        // Create a single seat element
        function createSeat(number) {
            const seat = document.createElement('div');
            const isBooked = bookedSeats.includes(number);
            
            seat.className = isBooked ? 'seat booked' : 'seat available';
            seat.setAttribute('data-seat-number', number);
            seat.setAttribute('tabindex', isBooked ? '-1' : '0');
            seat.setAttribute('aria-label', `Seat ${number} - ${isBooked ? 'Booked' : 'Available'}`);
            seat.textContent = number;
            
            if (!isBooked) {
                seat.addEventListener('click', () => selectSeat(number, seat));
                seat.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectSeat(number, seat);
                    }
                });
            }
            
            return seat;
        }

        // Handle seat selection
        function selectSeat(seatNumber, seatElement) {
            // Find which passenger this seat belongs to (if any)
            let currentPassenger = null;
            for (let p in selectedSeats) {
                if (selectedSeats[p] === seatNumber) {
                    currentPassenger = parseInt(p);
                    break;
                }
            }
            
            if (currentPassenger) {
                // Deselect this seat
                delete selectedSeats[currentPassenger];
                seatElement.className = 'seat available';
                seatElement.setAttribute('aria-label', `Seat ${seatNumber} - Available`);
            } else {
                // Find first available passenger slot
                let targetPassenger = null;
                for (let i = 1; i <= passengerCount; i++) {
                    if (!selectedSeats[i]) {
                        targetPassenger = i;
                        break;
                    }
                }
                
                if (targetPassenger) {
                    selectedSeats[targetPassenger] = seatNumber;
                    seatElement.className = `seat selected ${passengerColors[targetPassenger-1]}`;
                    seatElement.setAttribute('aria-label', `Seat ${seatNumber} - Selected for Passenger ${targetPassenger}`);
                } else {
                    showAlert('All passengers already have seats selected. Please deselect a seat first.');
                }
            }
            
            updateSummary();
            checkAllSeatsSelected();
            updateLastActivity();
        }

        // Check if all seats are selected
        function checkAllSeatsSelected() {
            const allSelected = Object.keys(selectedSeats).length === passengerCount;
            
            if (allSelected) {
                domCache.passengerDetails.style.display = 'block';
                updatePassengerForms();
                domCache.passengerDetails.scrollIntoView({ behavior: 'smooth' });
            } else {
                domCache.passengerDetails.style.display = 'none';
            }
        }

        // Sync form data between visible and hidden forms
        function syncFormData() {
            // First, let's make sure we have the latest data
            for (let i = 1; i <= passengerCount; i++) {
                // Sync passenger names
                const visibleNameInput = document.querySelector(`input[name="passenger_name_${i}"]`);
                if (visibleNameInput && visibleNameInput.value.trim()) {
                    const formNameInput = document.getElementById(`form_passenger_name_${i}`);
                    if (formNameInput) {
                        formNameInput.value = visibleNameInput.value.trim();
                    }
                }
                
                // Sync discount types - CRITICAL FIX
                const discountRadio = document.querySelector(`input[name="discount_type_${i}"]:checked`);
                if (discountRadio) {
                    const formDiscountInput = document.getElementById(`form_discount_type_${i}`);
                    if (formDiscountInput) {
                        formDiscountInput.value = discountRadio.value;
                        console.log(`Synced Passenger ${i} discount: ${discountRadio.value}`);
                    }
                }
                
                // Sync seat numbers
                const seatNum = selectedSeats[i];
                if (seatNum) {
                    const formSeatInput = document.getElementById(`form_seat_number_${i}`);
                    if (formSeatInput) {
                        formSeatInput.value = seatNum;
                    }
                }
            }
            
            // Sync payment proof file
            const paymentProofInput = document.getElementById('payment_proof');
            if (paymentProofInput && paymentProofInput.files && paymentProofInput.files.length > 0) {
                let hiddenPaymentInput = domCache.bookingForm.querySelector(`input[name="payment_proof"]`);
                if (!hiddenPaymentInput) {
                    hiddenPaymentInput = document.createElement('input');
                    hiddenPaymentInput.type = 'file';
                    hiddenPaymentInput.name = 'payment_proof';
                    hiddenPaymentInput.style.display = 'none';
                    domCache.bookingForm.appendChild(hiddenPaymentInput);
                }
                // Copy file data
                const dataTransfer = new DataTransfer();
                for (let file of paymentProofInput.files) {
                    dataTransfer.items.add(file);
                }
                hiddenPaymentInput.files = dataTransfer.files;
            }
            
            // Sync discount ID proof files
            for (let i = 1; i <= passengerCount; i++) {
                const discountFileInput = document.querySelector(`input[name="discount_id_proof_${i}"]`);
                if (discountFileInput && discountFileInput.files && discountFileInput.files.length > 0) {
                    let hiddenFileInput = domCache.bookingForm.querySelector(`input[name="discount_id_proof_${i}"]`);
                    if (!hiddenFileInput) {
                        hiddenFileInput = document.createElement('input');
                        hiddenFileInput.type = 'file';
                        hiddenFileInput.name = `discount_id_proof_${i}`;
                        hiddenFileInput.style.display = 'none';
                        domCache.bookingForm.appendChild(hiddenFileInput);
                    }
                    // Copy file data
                    const dataTransfer = new DataTransfer();
                    for (let file of discountFileInput.files) {
                        dataTransfer.items.add(file);
                    }
                    hiddenFileInput.files = dataTransfer.files;
                }
            }
        }

        // Real-time form synchronization helper
        function setupFormSyncing() {
            // Add event listeners to sync data as users type
            for (let i = 1; i <= passengerCount; i++) {
                const nameInput = document.querySelector(`input[name="passenger_name_${i}"]`);
                if (nameInput) {
                    nameInput.addEventListener('input', function() {
                        // Update hidden form data immediately
                        const hiddenInput = document.getElementById(`hidden_passenger_name_${i}`);
                        if (hiddenInput) {
                            hiddenInput.value = this.value.trim();
                        }
                    });
                }
                
                // Add listeners for discount changes
                const discountRadios = document.querySelectorAll(`input[name="discount_type_${i}"]`);
                discountRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        updateBookingForm(); // Refresh form data
                    });
                });
            }
        }

        // Update passenger forms
        function updatePassengerForms() {
            domCache.passengerForms.innerHTML = '';
            
            for (let i = 1; i <= passengerCount; i++) {
                const seatNum = selectedSeats[i] || 'Not selected';
                
                const passengerCard = document.createElement('div');
                passengerCard.className = 'passenger-card';
                passengerCard.innerHTML = `
                    <div class="passenger-header">
                        <h6 class="mb-0">
                            <i class="fas fa-user me-2" aria-hidden="true"></i>Passenger ${i}
                            <span class="badge bg-light text-dark ms-2">Seat ${seatNum}</span>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="passenger_name_${i}" class="form-label">Passenger Name*</label>
                                <input type="text" class="form-control" id="passenger_name_${i}" name="passenger_name_${i}" 
                                    placeholder="Enter full name" required
                                    oninput="sanitizeInput(this); updateSummary(); updateBookingForm();">
                            </div>
                            <div class="col-md-6">
                                <label for="seat_number_display_${i}" class="form-label">Seat Number</label>
                                <input type="text" class="form-control bg-light" id="seat_number_display_${i}" 
                                    value="Seat ${seatNum}" readonly>
                                <input type="hidden" name="seat_number_${i}" value="${selectedSeats[i] || ''}">
                            </div>
                        </div>
                        
                        <div class="discount-selector">
                            <h6><i class="fas fa-tag me-2" aria-hidden="true"></i>Discount Type</h6>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input discount-radio" type="radio" 
                                            name="discount_type_${i}" id="regular_${i}" value="regular" checked>
                                        <label class="form-check-label" for="regular_${i}">Regular</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input discount-radio" type="radio" 
                                            name="discount_type_${i}" id="student_${i}" value="student">
                                        <label class="form-check-label" for="student_${i}">Student (20% off)</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input discount-radio" type="radio" 
                                            name="discount_type_${i}" id="senior_${i}" value="senior">
                                        <label class="form-check-label" for="senior_${i}">Senior (20% off)</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input discount-radio" type="radio" 
                                            name="discount_type_${i}" id="pwd_${i}" value="pwd">
                                        <label class="form-check-label" for="pwd_${i}">PWD (20% off)</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="discount-upload-section" id="discount_upload_${i}" style="display: none;">
                                <h6><i class="fas fa-id-card me-2" aria-hidden="true"></i>Upload ID Proof</h6>
                                <input type="file" class="form-control" name="discount_id_proof_${i}" 
                                    accept="image/*,.pdf" aria-describedby="discount_help_${i}">
                                <small id="discount_help_${i}" class="text-muted">
                                    Required for online payments. For counter payment, present ID at terminal.
                                </small>
                            </div>
                        </div>
                    </div>
                `;
                
                domCache.passengerForms.appendChild(passengerCard);
            }
            
            // Setup enhanced discount handlers after creating all forms
            setupEnhancedDiscountHandlers();
            
            // Setup real-time form syncing after creating forms
            setupFormSyncing();
            
            if (Object.keys(selectedSeats).length === passengerCount) {
                domCache.paymentSelection.style.display = 'block';
            } else {
                domCache.paymentSelection.style.display = 'none';
            }
        }

        // Handle payment method change
        function handlePaymentMethodChange() {
            const proofSection = document.getElementById('payment-proof-section');
            if (this.value === 'gcash' || this.value === 'paymaya') {
                proofSection.style.display = 'block';
                
                // Make discount ID uploads required for online payments
                document.querySelectorAll('.discount-upload-section').forEach(section => {
                    if (section.style.display !== 'none') {
                        section.classList.add('required');
                    }
                });
            } else {
                proofSection.style.display = 'none';
                
                // Remove required class for counter payments
                document.querySelectorAll('.discount-upload-section').forEach(section => {
                    section.classList.remove('required');
                });
            }
            
            updateBookingForm();
            updateLastActivity();
        }

        // Update booking summary
        function updateSummary() {
            domCache.passengerSummary.innerHTML = '';
            domCache.fareDetails.innerHTML = '';
            
            let totalAmount = 0;
            let totalSavings = 0;
            
            for (let i = 1; i <= passengerCount; i++) {
                const seatNum = selectedSeats[i] || 'Not selected';
                
                // Get the SPECIFIC discount type for THIS passenger
                const discountRadio = document.querySelector(`input[name="discount_type_${i}"]:checked`);
                const discountType = discountRadio ? discountRadio.value : 'regular';
                
                let fare = farePerPerson;
                let discountText = '';
                let discountAmount = 0;
                
                // Apply discount ONLY for this specific passenger's discount type
                if (discountType !== 'regular') {
                    discountAmount = farePerPerson * 0.2; // 20% discount
                    fare = farePerPerson * 0.8;
                    discountText = ` (${discountType} - 20% off)`;
                    totalSavings += discountAmount;
                }
                
                totalAmount += fare;
                
                // Debug logging
                console.log(`Passenger ${i}: Discount Type = ${discountType}, Base Fare = ${farePerPerson}, Final Fare = ${fare}, Discount Amount = ${discountAmount}`);
                
                const summaryItem = document.createElement('div');
                summaryItem.className = 'passenger-summary';
                summaryItem.innerHTML = `
                    <strong>Passenger ${i}</strong><br>
                    <small>Seat: ${seatNum}${discountText}</small><br>
                    <small>Fare: ₱${fare.toFixed(2)}</small>
                    ${discountAmount > 0 ? `<br><small class="text-success">Saved: ₱${discountAmount.toFixed(2)}</small>` : ''}
                `;
                domCache.passengerSummary.appendChild(summaryItem);
                
                const fareItem = document.createElement('div');
                fareItem.className = 'd-flex justify-content-between';
                fareItem.innerHTML = `
                    <span>Passenger ${i} (Seat ${seatNum})${discountText}:</span> 
                    <span>₱${fare.toFixed(2)}</span>
                `;
                domCache.fareDetails.appendChild(fareItem);
            }
            
            // Add total savings display if there are any savings
            if (totalSavings > 0) {
                const savingsItem = document.createElement('div');
                savingsItem.className = 'd-flex justify-content-between text-success fw-bold border-top pt-2 mt-2';
                savingsItem.innerHTML = `
                    <span>Total Savings:</span> 
                    <span>₱${totalSavings.toFixed(2)}</span>
                `;
                domCache.fareDetails.appendChild(savingsItem);
            }
            
            domCache.totalAmount.innerHTML = `Total: ₱${totalAmount.toFixed(2)}`;
            
            // Update booking form if all required data is available
            updateBookingForm();
            updateLastActivity();
        }
        
        // Update booking form data
        function updateBookingForm() {
            const allSeatsSelected = Object.keys(selectedSeats).length === passengerCount;
            const paymentMethodSelected = document.querySelector('input[name="payment_method"]:checked');
            
            if (allSeatsSelected && paymentMethodSelected) {
                domCache.bookingForm.style.display = 'block';
                
                // Set form values
                domCache.formBusId.value = selectedBus.id;
                domCache.formPassengerCount.value = passengerCount;
                domCache.formPaymentMethod.value = paymentMethodSelected.value;
                
                // Clear and regenerate passenger data for form submission
                domCache.formPassengerData.innerHTML = '';
                
                for (let i = 1; i <= passengerCount; i++) {
                    const seatNum = selectedSeats[i];
                    
                    // Get the SPECIFIC discount type for THIS passenger - FIXED
                    const discountRadio = document.querySelector(`input[name="discount_type_${i}"]:checked`);
                    const discountType = discountRadio ? discountRadio.value : 'regular';
                    
                    const nameInput = document.querySelector(`input[name="passenger_name_${i}"]`);
                    const passengerName = nameInput ? nameInput.value.trim() : '';
                    
                    // Debug logging to verify correct discount types
                    console.log(`Form Update - Passenger ${i}: Name=${passengerName}, Seat=${seatNum}, Discount=${discountType}`);
                    
                    // Create hidden inputs with unique IDs to prevent conflicts
                    const hiddenInputsHTML = `
                        <input type="hidden" name="passenger_name_${i}" value="${passengerName}" id="form_passenger_name_${i}">
                        <input type="hidden" name="seat_number_${i}" value="${seatNum}" id="form_seat_number_${i}">
                        <input type="hidden" name="discount_type_${i}" value="${discountType}" id="form_discount_type_${i}">
                    `;
                    
                    domCache.formPassengerData.innerHTML += hiddenInputsHTML;
                }
                
                // Additional debug: Log all form data that will be submitted
                console.log('=== FORM DATA TO BE SUBMITTED ===');
                const formData = new FormData(domCache.bookingForm);
                for (let [key, value] of formData.entries()) {
                    if (key.includes('discount_type') || key.includes('passenger_name') || key.includes('seat_number')) {
                        console.log(`${key}: ${value}`);
                    }
                }
                console.log('=== END FORM DATA ===');
            } else {
                domCache.bookingForm.style.display = 'none';
            }
        }
        
        // Handle form submission
        function handleFormSubmission(e) {
            console.log('=== FORM SUBMISSION STARTED ===');
            
            // First, update the form data to ensure we have the latest values
            updateBookingForm();
            
            // Then sync any additional data
            syncFormData();
            
            let isValid = true;
            const errors = [];
            
            // Validate all passenger data
            for (let i = 1; i <= passengerCount; i++) {
                // Check passenger name
                const nameInput = document.querySelector(`input[name="passenger_name_${i}"]`);
                const name = nameInput ? nameInput.value.trim() : '';
                
                if (!name) {
                    errors.push(`Please enter name for Passenger ${i}`);
                    isValid = false;
                    if (nameInput) nameInput.classList.add('is-invalid');
                } else {
                    if (nameInput) nameInput.classList.remove('is-invalid');
                }
                
                // Check seat selection
                if (!selectedSeats[i]) {
                    errors.push(`Please select a seat for Passenger ${i}`);
                    isValid = false;
                }
                
                // Validate discount type selection
                const discountRadio = document.querySelector(`input[name="discount_type_${i}"]:checked`);
                if (!discountRadio) {
                    errors.push(`Please select discount type for Passenger ${i}`);
                    isValid = false;
                } else {
                    console.log(`Passenger ${i} discount validated: ${discountRadio.value}`);
                }
            }
            
            // Check payment method
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            if (!paymentMethod) {
                errors.push('Please select a payment method');
                isValid = false;
            } else {
                const paymentValue = paymentMethod.value;
                
                // Check payment proof for online payments
                if ((paymentValue === 'gcash' || paymentValue === 'paymaya')) {
                    const paymentProofInput = document.getElementById('payment_proof');
                    if (!paymentProofInput.files || paymentProofInput.files.length === 0) {
                        errors.push('Please upload payment proof for online payment');
                        isValid = false;
                        paymentProofInput.classList.add('is-invalid');
                    } else {
                        paymentProofInput.classList.remove('is-invalid');
                    }
                    
                    // Check discount IDs for online payments
                    for (let i = 1; i <= passengerCount; i++) {
                        const discountRadio = document.querySelector(`input[name="discount_type_${i}"]:checked`);
                        if (discountRadio && discountRadio.value !== 'regular') {
                            const idProofInput = document.querySelector(`input[name="discount_id_proof_${i}"]`);
                            if (!idProofInput || !idProofInput.files || idProofInput.files.length === 0) {
                                errors.push(`Please upload ID proof for Passenger ${i} (${discountRadio.value} discount)`);
                                isValid = false;
                                if (idProofInput) idProofInput.classList.add('is-invalid');
                            } else {
                                if (idProofInput) idProofInput.classList.remove('is-invalid');
                            }
                        }
                    }
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                showAlert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Final validation: Log what will actually be submitted
            console.log('=== FINAL FORM DATA BEFORE SUBMISSION ===');
            const finalFormData = new FormData(domCache.bookingForm);
            for (let [key, value] of finalFormData.entries()) {
                console.log(`${key}: ${value}`);
            }
            console.log('=== END FINAL FORM DATA ===');
            
            updateLastActivity();
            return true;
        }

        function setupEnhancedDiscountHandlers() {
            // This function should be called after creating passenger forms
            for (let i = 1; i <= passengerCount; i++) {
                const discountRadios = document.querySelectorAll(`input[name="discount_type_${i}"]`);
                discountRadios.forEach(radio => {
                    radio.addEventListener('change', function(e) {
                        const passengerIndex = i;
                        const uploadSection = document.getElementById(`discount_upload_${passengerIndex}`);
                        
                        if (this.value !== 'regular') {
                            uploadSection.style.display = 'block';
                        } else {
                            uploadSection.style.display = 'none';
                        }
                        
                        // Critical: Update summary and form data immediately
                        updateSummary();
                        updateBookingForm();
                        
                        // Debug logging with more detail
                        console.log(`Passenger ${passengerIndex} discount changed from ${e.target.dataset.previousValue || 'unknown'} to: ${this.value}`);
                        
                        // Store previous value for debugging
                        e.target.dataset.previousValue = this.value;
                    });
                });
            }
        }

        // Add CSRF token to form
        function addCsrfToken() {
            const csrfToken = generateCsrfToken();
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = csrfToken;
                domCache.bookingForm.appendChild(csrfInput);
            }
        }

        // Generate CSRF token (simplified - should be server-side in production)
        function generateCsrfToken() {
            return '<?php echo bin2hex(random_bytes(32)); ?>';
        }

        // Sanitize input
        function sanitizeInput(inputElement) {
            inputElement.value = inputElement.value.replace(/</g, "&lt;").replace(/>/g, "&gt;");
        }

        // Show alert message
        function showAlert(message) {
            // Replace with your preferred alert/notification system
            alert(message);
        }

    
        // Initialize the application
        init();

        document.addEventListener('DOMContentLoaded', function() {
            const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
            const paymentProofSection = document.getElementById('payment-proof-section');
            const gcashQRSection = document.getElementById('gcash-qr-section');
            const paymayaQRSection = document.getElementById('paymaya-qr-section');

            paymentMethods.forEach(method => {
                method.addEventListener('change', function() {
                    // Remove selected class from all cards
                    document.querySelectorAll('.payment-method-card').forEach(card => {
                        card.classList.remove('selected');
                    });

                    // Add selected class to current card
                    this.closest('.payment-method-card').classList.add('selected');

                    // Hide all QR sections
                    gcashQRSection.style.display = 'none';
                    paymayaQRSection.style.display = 'none';

                    if (this.value === 'gcash') {
                        paymentProofSection.style.display = 'block';
                        gcashQRSection.style.display = 'block';
                    } else if (this.value === 'paymaya') {
                        paymentProofSection.style.display = 'block';
                        paymayaQRSection.style.display = 'block';
                    } else if (this.value === 'counter') {
                        paymentProofSection.style.display = 'none';
                    }
                });
            });
        });
    </script>
</body>
</html>