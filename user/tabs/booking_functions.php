<?php

function logBookingActivity($user_id, $booking_references, $action, $details) {
    try {
        // Simple logging function - enhance as needed
        $log_message = "Booking Activity - User: $user_id, Action: $action, Details: $details, References: " . implode(',', $booking_references);
        error_log($log_message);
        
        // Optional: Store in database
        // You can add database logging here if you have a logs table
        
        return true;
    } catch (Exception $e) {
        error_log("Error logging booking activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate QR code data for booking verification
 */
function generateBookingQR($booking_references, $receipt_data) {
    try {
        // Generate QR data structure
        $qr_data = [
            'type' => 'bus_booking',
            'references' => $booking_references,
            'total_amount' => array_sum(array_column($receipt_data, 'individual_fare')),
            'passenger_count' => count($receipt_data),
            'timestamp' => time(),
            'verification_code' => strtoupper(substr(md5(implode('', $booking_references)), 0, 8))
        ];
        
        return json_encode($qr_data);
    } catch (Exception $e) {
        error_log("Error generating QR data: " . $e->getMessage());
        return json_encode(['error' => 'Failed to generate QR data']);
    }
}

/**
 * Process payment proof file upload
 */
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

/**
 * Process discount ID file upload
 */
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

/**
 * Fetch booked seats for a specific bus and date
 */
function getBookedSeats($conn, $busId, $date) {
    $booked_seats = [];
    
    if ($busId && $date) {
        try {
            $query = "SELECT seat_number FROM bookings 
                      WHERE bus_id = ? 
                      AND (DATE(booking_date) = ? OR booking_date = '0000-00-00') 
                      AND booking_status = 'confirmed'";
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

/**
 * Create comprehensive booking summary for post-processing
 */
function processBookingSuccess($conn, $booking_references, $user_id, $user_email, $user_name) {
    try {
        // Create comprehensive booking summary
        $booking_summary = [
            'references' => $booking_references,
            'total_bookings' => count($booking_references),
            'booking_time' => date('Y-m-d H:i:s'),
            'user_id' => $user_id
        ];
        
        // Get detailed booking information
        $placeholders = str_repeat('?,', count($booking_references) - 1) . '?';
        $summary_query = "SELECT 
                            b.*,
                            bus.bus_type,
                            bus.plate_number,
                            bus.route_name,
                            r.origin,
                            r.destination,
                            r.fare,
                            s.departure_time,
                            s.arrival_time,
                            s.trip_number
                          FROM bookings b
                          LEFT JOIN buses bus ON b.bus_id = bus.id
                          LEFT JOIN routes r ON bus.route_id = r.id
                          LEFT JOIN schedules s ON b.bus_id = s.bus_id AND b.trip_number = s.trip_number
                          WHERE b.booking_reference IN ($placeholders)
                          ORDER BY b.seat_number";
        
        $summary_stmt = $conn->prepare($summary_query);
        $types = str_repeat('s', count($booking_references));
        $summary_stmt->bind_param($types, ...$booking_references);
        $summary_stmt->execute();
        $summary_result = $summary_stmt->get_result();
        
        $detailed_bookings = [];
        $total_amount = 0;
        
        while ($row = $summary_result->fetch_assoc()) {
            $detailed_bookings[] = $row;
            $total_amount += $row['individual_fare'];
        }
        
        $booking_summary['detailed_bookings'] = $detailed_bookings;
        $booking_summary['total_amount'] = $total_amount;
        
        // Store in session for receipt
        $_SESSION['booking_summary'] = $booking_summary;
        
        return $booking_summary;
        
    } catch (Exception $e) {
        error_log("Error processing booking success: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate and sanitize booking form data
 */
function sanitizeBookingData($post_data, $files_data) {
    $sanitized = [];
    
    // Sanitize basic fields
    $sanitized['bus_id'] = filter_var($post_data['bus_id'] ?? 0, FILTER_VALIDATE_INT);
    $sanitized['booking_date'] = filter_var($post_data['booking_date'] ?? '', FILTER_SANITIZE_STRING);
    $sanitized['payment_method'] = filter_var($post_data['payment_method'] ?? '', FILTER_SANITIZE_STRING);
    
    // Sanitize passengers data
    $sanitized['passengers'] = [];
    if (isset($post_data['passengers']) && is_array($post_data['passengers'])) {
        foreach ($post_data['passengers'] as $index => $passenger) {
            $sanitized['passengers'][$index] = [
                'name' => filter_var($passenger['name'] ?? '', FILTER_SANITIZE_STRING),
                'seat_number' => filter_var($passenger['seat_number'] ?? 0, FILTER_VALIDATE_INT),
                'discount_type' => filter_var($passenger['discount_type'] ?? 'regular', FILTER_SANITIZE_STRING)
            ];
        }
    }
    
    // Handle file uploads
    $sanitized['files'] = [
        'payment_proof' => $files_data['payment_proof'] ?? null,
        'discount_ids' => []
    ];
    
    // Process discount ID files
    foreach ($files_data as $key => $file) {
        if (strpos($key, 'discount_id_proof_') === 0) {
            $passenger_index = str_replace('discount_id_proof_', '', $key);
            $sanitized['files']['discount_ids'][$passenger_index] = $file;
        }
    }
    
    return $sanitized;
}

/**
 * Enhanced error handling for booking validation
 */
function validateBookingRequest($sanitized_data, $conn) {
    $errors = [];
    $warnings = [];
    
    // Validate bus selection
    if (!$sanitized_data['bus_id'] || $sanitized_data['bus_id'] <= 0) {
        $errors[] = "Please select a valid bus";
    } else {
        // Check if bus is still available and active
        $bus_check = "SELECT id, status, seat_capacity FROM buses WHERE id = ? AND status = 'Active'";
        $bus_stmt = $conn->prepare($bus_check);
        $bus_stmt->bind_param("i", $sanitized_data['bus_id']);
        $bus_stmt->execute();
        $bus_result = $bus_stmt->get_result();
        
        if ($bus_result->num_rows === 0) {
            $errors[] = "Selected bus is no longer available";
        }
    }
    
    // Validate booking date
    if (empty($sanitized_data['booking_date'])) {
        $errors[] = "Please select a booking date";
    } else {
        $booking_date = new DateTime($sanitized_data['booking_date']);
        $today = new DateTime('today');
        $max_date = new DateTime('+30 days');
        
        if ($booking_date < $today) {
            $errors[] = "Booking date cannot be in the past";
        } elseif ($booking_date > $max_date) {
            $warnings[] = "Booking date is more than 30 days in advance";
        }
    }
    
    // Validate passengers
    if (empty($sanitized_data['passengers'])) {
        $errors[] = "Please add at least one passenger";
    } else {
        $seat_numbers = [];
        foreach ($sanitized_data['passengers'] as $index => $passenger) {
            $passenger_num = $index + 1;
            
            if (empty($passenger['name'])) {
                $errors[] = "Please enter name for passenger #{$passenger_num}";
            }
            
            if (!$passenger['seat_number'] || $passenger['seat_number'] <= 0) {
                $errors[] = "Please select a seat for passenger #{$passenger_num}";
            } else {
                if (in_array($passenger['seat_number'], $seat_numbers)) {
                    $errors[] = "Seat {$passenger['seat_number']} is selected multiple times";
                }
                $seat_numbers[] = $passenger['seat_number'];
            }
            
            if (empty($passenger['discount_type'])) {
                $errors[] = "Please select discount type for passenger #{$passenger_num}";
            }
        }
        
        // Check for seat conflicts with existing bookings
        if (!empty($seat_numbers) && $sanitized_data['bus_id']) {
            $booked_seats = checkSeatAvailability(
                $sanitized_data['bus_id'], 
                $seat_numbers, 
                $sanitized_data['booking_date'],
                $conn
            );
            
            if (!empty($booked_seats)) {
                $errors[] = "The following seats are already booked: " . implode(', ', $booked_seats);
            }
        }
    }
    
    // Validate payment method
    $valid_payment_methods = ['counter', 'gcash', 'paymaya'];
    if (!in_array($sanitized_data['payment_method'], $valid_payment_methods)) {
        $errors[] = "Please select a valid payment method";
    }
    
    // Validate file uploads
    if (in_array($sanitized_data['payment_method'], ['gcash', 'paymaya'])) {
        if (!$sanitized_data['files']['payment_proof'] || 
            $sanitized_data['files']['payment_proof']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload payment proof for " . strtoupper($sanitized_data['payment_method']);
        }
    }
    
    // Validate discount ID uploads
    foreach ($sanitized_data['passengers'] as $index => $passenger) {
        if ($passenger['discount_type'] !== 'regular') {
            $discount_file = $sanitized_data['files']['discount_ids'][$index] ?? null;
            if (!$discount_file || $discount_file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Please upload valid ID for passenger " . ($index + 1) . " (" . 
                           ucfirst($passenger['discount_type']) . " discount)";
            }
        }
    }
    
    return [
        'is_valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}

/**
 * Check seat availability for booking validation
 */
function checkSeatAvailability($bus_id, $seat_numbers, $booking_date, $conn) {
    $booked_seats = [];
    
    try {
        $placeholders = str_repeat('?,', count($seat_numbers) - 1) . '?';
        $check_query = "SELECT seat_number FROM bookings 
                        WHERE bus_id = ? 
                        AND (booking_date = ? OR booking_date = '0000-00-00')
                        AND seat_number IN ($placeholders) 
                        AND booking_status = 'confirmed'";

        $check_stmt = $conn->prepare($check_query);
        $params = array_merge([$bus_id, $booking_date], $seat_numbers);
        $types = 'is' . str_repeat('i', count($seat_numbers));
        $check_stmt->bind_param($types, ...$params);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $booked_seats[] = $row['seat_number'];
        }
    } catch (Exception $e) {
        error_log("Error checking seat availability: " . $e->getMessage());
    }
    
    return $booked_seats;
}

/**
 * Enhanced booking reference generator with collision detection
 */
function generateUniqueBookingReference($conn, $max_attempts = 10) {
    $attempts = 0;
    
    do {
        $reference = 'BK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Check if reference already exists
        $check_query = "SELECT id FROM bookings WHERE booking_reference = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $reference);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows === 0) {
            return $reference; // Unique reference found
        }
        
        $attempts++;
    } while ($attempts < $max_attempts);
    
    // Fallback with timestamp if max attempts reached
    return 'BK-' . date('Ymd-His') . '-' . strtoupper(substr(uniqid(), -4));
}

/**
 * Create booking notification for admin dashboard
 */
function createBookingNotification($conn, $booking_references, $user_name, $total_amount) {
    try {
        $notification_message = "New booking created by {$user_name}. " . 
                               count($booking_references) . " passenger(s), " . 
                               "Total: ₱" . number_format($total_amount, 2);
        
        // Check if admin_notifications table exists
        $table_check = "SHOW TABLES LIKE 'admin_notifications'";
        $table_result = $conn->query($table_check);
        
        if ($table_result && $table_result->num_rows > 0) {
            $notification_query = "INSERT INTO admin_notifications (type, title, message, data, created_at) 
                                  VALUES ('booking', 'New Booking', ?, ?, NOW())";
            
            $notification_data = json_encode([
                'booking_references' => $booking_references,
                'user_name' => $user_name,
                'total_amount' => $total_amount,
                'passenger_count' => count($booking_references)
            ]);
            
            $stmt = $conn->prepare($notification_query);
            if ($stmt) {
                $stmt->bind_param("ss", $notification_message, $notification_data);
                $stmt->execute();
            }
        } else {
            // Just log if table doesn't exist
            error_log("Admin notification: " . $notification_message);
        }
    } catch (Exception $e) {
        error_log("Error creating booking notification: " . $e->getMessage());
    }
}

/**
 * Send booking confirmation email (optional)
 */
function sendBookingConfirmationEmail($user_email, $user_name, $receipt_data, $booking_references) {
    try {
        // This is a placeholder function
        // Implement actual email sending using PHPMailer or similar
        
        $subject = "Booking Confirmation - " . implode(', ', $booking_references);
        $message = "Dear {$user_name},\n\n";
        $message .= "Your bus booking has been confirmed!\n";
        $message .= "Booking References: " . implode(', ', $booking_references) . "\n";
        $message .= "Total Amount: ₱" . number_format(array_sum(array_column($receipt_data, 'individual_fare')), 2) . "\n\n";
        $message .= "Thank you for choosing our service.\n";
        
        // For now, just log the email content
        error_log("Email would be sent to {$user_email}: " . $subject);
        error_log("Email content: " . $message);
        
        // Return true to simulate successful email sending
        return true;
        
        // Uncomment and implement actual email sending when ready:
        // return mail($user_email, $subject, $message);
        
    } catch (Exception $e) {
        error_log("Error sending confirmation email: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate total fare for passengers
 */
function calculateTotalFare($passengers, $base_fare) {
    $total = 0;
    
    foreach ($passengers as $passenger) {
        $fare = $base_fare;
        if ($passenger['discount_type'] !== 'regular') {
            $fare = $base_fare * 0.8; // 20% discount
        }
        $total += $fare;
    }
    
    return $total;
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $max_size = 5242880, $allowed_types = ['image/jpeg', 'image/png', 'image/gif']) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'File upload error: ' . $file['error']];
    }
    
    if ($file['size'] > $max_size) {
        return ['valid' => false, 'error' => 'File size too large (max ' . ($max_size / 1024 / 1024) . 'MB)'];
    }
    
    $file_info = getimagesize($file['tmp_name']);
    if ($file_info === false || !in_array($file_info['mime'], $allowed_types)) {
        return ['valid' => false, 'error' => 'Invalid file type'];
    }
    
    return ['valid' => true];
}

/**
 * Clean up old upload files (maintenance function)
 */
function cleanupOldUploads($days_old = 30) {
    try {
        $upload_dirs = [
            __DIR__ . '/uploads/payment_proofs/',
            __DIR__ . '/uploads/discount_ids/'
        ];
        
        $cutoff_time = time() - ($days_old * 24 * 60 * 60);
        $cleaned_files = 0;
        
        foreach ($upload_dirs as $dir) {
            if (!is_dir($dir)) continue;
            
            $files = glob($dir . '*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff_time) {
                    if (unlink($file)) {
                        $cleaned_files++;
                    }
                }
            }
        }
        
        error_log("Cleanup completed: removed {$cleaned_files} old files");
        return $cleaned_files;
        
    } catch (Exception $e) {
        error_log("Error during cleanup: " . $e->getMessage());
        return 0;
    }
}
?>