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

// Initialize debug array
$debug_messages = [];

// Database connection
require_once "../../../backend/connections/config.php"; 
require_once "../../../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure booking_reference column exists
try {
    $alter_query = "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS booking_reference VARCHAR(20) DEFAULT NULL";
    $conn->query($alter_query);
} catch (Exception $e) {
    error_log("Error checking/adding booking_reference column: " . $e->getMessage());
    // Continue anyway
}

// Check if booking ID is provided
if (!isset($_GET['booking_id']) || empty($_GET['booking_id'])) {
    header("Location: booking.php");
    exit;
}

$booking_id = intval($_GET['booking_id']);
$booking_data = null;
$bus_data = null;
$route_data = null;

// Fetch booking details
try {
    $debug_messages[] = "Starting fetch of booking ID: " . $booking_id;
    
    // Adapt query to match your database structure - handle missing full_name column
    $booking_query = "SELECT b.*, 
                     u.email as passenger_email,
                     u.contact_number as passenger_phone,
                     CONCAT(u.first_name, ' ', u.last_name) as passenger_name
                     FROM bookings b
                     LEFT JOIN users u ON b.user_id = u.id
                     WHERE b.id = ? AND b.user_id = ?";
    $booking_stmt = $conn->prepare($booking_query);
    $booking_stmt->bind_param("ii", $booking_id, $user_id);
    $booking_stmt->execute();
    $booking_result = $booking_stmt->get_result();
    
    if ($booking_result && $booking_result->num_rows > 0) {
        $booking_data = $booking_result->fetch_assoc();
        $debug_messages[] = "Booking data fetched successfully";
        
        // Check if booking_reference exists, if not generate one
        if (empty($booking_data['booking_reference'])) {
            $booking_reference = 'BK-' . date('Ymd') . '-' . $booking_id;
            
            // Update the booking with the reference number
            $update_query = "UPDATE bookings SET booking_reference = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $booking_reference, $booking_id);
            
            if ($update_stmt->execute()) {
                $booking_data['booking_reference'] = $booking_reference;
                $debug_messages[] = "Generated and saved new booking reference: " . $booking_reference;
            }
        }
        
        // Fetch bus details
        $bus_query = "SELECT b.*, s.departure_time, s.arrival_time 
                     FROM buses b
                     LEFT JOIN schedules s ON b.id = s.bus_id
                     WHERE b.id = ?";
        $bus_stmt = $conn->prepare($bus_query);
        $bus_stmt->bind_param("i", $booking_data['bus_id']);
        $bus_stmt->execute();
        $bus_result = $bus_stmt->get_result();
        
        if ($bus_result && $bus_result->num_rows > 0) {
            $bus_data = $bus_result->fetch_assoc();
            $debug_messages[] = "Bus data fetched successfully";
            
            // Try to extract origin and destination - handle different database structures
            if (isset($bus_data['route_name']) && strpos($bus_data['route_name'], '→') !== false) {
                // Extract from route_name field
                $route_parts = explode(' → ', $bus_data['route_name']);
                $origin = $route_parts[0] ?? '';
                $destination = $route_parts[1] ?? '';
            } else {
                // Check if origin/destination are directly in bus table
                $origin = $bus_data['origin'] ?? '';
                $destination = $bus_data['destination'] ?? '';
            }
            
            // Fetch fare from routes table
            $route_query = "SELECT * FROM routes WHERE origin = ? AND destination = ?";
            $route_stmt = $conn->prepare($route_query);
            $route_stmt->bind_param("ss", $origin, $destination);
            $route_stmt->execute();
            $route_result = $route_stmt->get_result();
            
            if ($route_result && $route_result->num_rows > 0) {
                $route_data = $route_result->fetch_assoc();
                $debug_messages[] = "Route data fetched successfully";
            } else {
                $debug_messages[] = "Route data not found. Checking schedules for fare info.";
                
                // Try to get fare from schedules if route fails
                $schedule_query = "SELECT fare_amount FROM schedules WHERE bus_id = ? LIMIT 1";
                $schedule_stmt = $conn->prepare($schedule_query);
                $schedule_stmt->bind_param("i", $booking_data['bus_id']);
                $schedule_stmt->execute();
                $schedule_result = $schedule_stmt->get_result();
                
                if ($schedule_result && $schedule_result->num_rows > 0) {
                    $schedule_data = $schedule_result->fetch_assoc();
                    $route_data = ['fare' => $schedule_data['fare_amount']];
                    $debug_messages[] = "Got fare from schedules instead: " . $schedule_data['fare_amount'];
                }
            }
        } else {
            $debug_messages[] = "Bus data not found for bus ID: " . $booking_data['bus_id'];
        }
    } else {
        // Booking not found or doesn't belong to user
        $debug_messages[] = "Booking not found or doesn't belong to user ID: $user_id";
        header("Location: ../mybookings.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Error fetching booking details: " . $e->getMessage());
    $debug_messages[] = "Exception: " . $e->getMessage();
    header("Location: ../mybookings.php");
    exit;
}

// If booking data is not retrieved, redirect
if (!$booking_data) {
    $debug_messages[] = "Essential data missing, redirecting";
    header("Location: ../mybookings.php");
    exit;
}

// Format date and time
// Handle date format conversion if needed
if (isset($booking_data['booking_date'])) {
    $booking_date = strtotime($booking_data['booking_date']);
    $booking_date_formatted = date('F d, Y', $booking_date);
} else {
    $booking_date_formatted = 'N/A';
}

if (isset($booking_data['created_at'])) {
    $created_at_formatted = date('F d, Y h:i A', strtotime($booking_data['created_at']));
} else {
    $created_at_formatted = date('F d, Y h:i A');
}

// Handle potentially missing schedule data
$departure_time = isset($bus_data['departure_time']) ? date('h:i A', strtotime($bus_data['departure_time'])) : 'N/A';
$arrival_time = isset($bus_data['arrival_time']) ? date('h:i A', strtotime($bus_data['arrival_time'])) : 'N/A';

// Set default values if data is missing
$origin = $origin ?? 'N/A';
$destination = $destination ?? 'N/A';
$fare_amount = isset($route_data['fare']) ? $route_data['fare'] : 0.00;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../../css/navfot.css">
    <style>
        /* Regular styles */
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 10px;
        }
        
        .receipt-header {
            padding-bottom: 20px;
            border-bottom: 2px dashed #dee2e6;
            margin-bottom: 20px;
        }
        
        .company-logo {
            max-height: 80px;
        }
        
        .receipt-title {
            color: #28a745;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .booking-ref {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 10px;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 20px;
            border: 1px dashed #0d6efd;
        }
        
        .ticket-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .ticket-info .icon {
            color: #0d6efd;
            font-size: 1.2rem;
            width: 30px;
            text-align: center;
        }
        
        .passenger-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .receipt-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 2px dashed #dee2e6;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        
        .barcode {
            height: 60px;
            margin: 15px auto;
            display: block;
        }
        
        .qr-code {
            height: 100px;
            margin: 15px auto;
            display: block;
        }
        
        .action-buttons {
            margin: 30px 0;
        }
        
        .bus-details {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .fare-details {
            background-color: #e7f1ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .divider {
            height: 2px;
            background-color: #dee2e6;
            margin: 15px 0;
        }
        
        .debug-info {
            font-size: 0.8rem;
            border-left: 5px solid #17a2b8;
            margin-bottom: 20px;
        }
        
        /* Print-specific styles */
        @media print {
            body {
                background-color: #fff;
                font-size: 12pt;
            }
            
            .receipt-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            
            .action-buttons,
            nav,
            footer,
            .print-instructions,
            .debug-info {
                display: none !important;
            }
            
            .receipt-header {
                text-align: center;
            }
            
            .booking-ref {
                border: 1px dashed #000;
                background-color: transparent;
                color: #000;
            }
            
            .ticket-info,
            .passenger-info,
            .bus-details,
            .fare-details {
                background-color: transparent;
                border: 1px solid #ddd;
            }
            
            .col-md-6 {
                width: 50%;
                float: left;
            }
            
            /* Improve printing of QR and barcode images */
            .barcode, .qr-code {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
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
                        <a class="nav-link" href="../../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../booking.php">Book Ticket</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="print-instructions alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>This is your booking receipt. You can print this page or save it as a PDF for future reference.
                </div>
                
                <!-- Receipt Container -->
                <div class="receipt-container">
                    <!-- Receipt Header -->
                    <div class="receipt-header text-center">
                        <div class="row align-items-center">
                            <!--<div class="col-md-4">
                                <img src="../img/ceres-logo.png" alt="Ceres Bus Logo" class="company-logo">
                            </div>-->
                            <div class="col-md-8 text-md-start text-center">
                                <h3 class="receipt-title">Ceres Bus Ticket System</h3>
                                <p class="mb-0">ISAT-U Commuters Special Service</p>
                                <p class="mb-0">Ceres Bus Terminal, Iloilo City</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Booking Reference -->
                    <div class="booking-ref text-center">
                        <div class="row">
                            <div class="col-md-6 text-md-end">
                                <strong>Booking Reference:</strong>
                            </div>
                            <div class="col-md-6 text-md-start">
                                <?php echo htmlspecialchars($booking_data['booking_reference'] ?? 'N/A'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ticket Info -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="ticket-info">
                                <h5><i class="fas fa-ticket-alt me-2"></i>Ticket Information</h5>
                                <div class="divider"></div>
                                <p>
                                    <span class="icon"><i class="fas fa-route"></i></span>
                                    <strong>Route:</strong> <?php echo htmlspecialchars($origin); ?> to <?php echo htmlspecialchars($destination); ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-calendar-alt"></i></span>
                                    <strong>Travel Date:</strong> <?php echo $booking_date_formatted; ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-clock"></i></span>
                                    <strong>Departure:</strong> <?php echo $departure_time; ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-clock"></i></span>
                                    <strong>Arrival:</strong> <?php echo $arrival_time; ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-chair"></i></span>
                                    <strong>Seat Number:</strong> <?php echo $booking_data['seat_number']; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="passenger-info">
                                <h5><i class="fas fa-user me-2"></i>Passenger Details</h5>
                                <div class="divider"></div>
                                <p>
                                    <span class="icon"><i class="fas fa-user"></i></span>
                                    <strong>Name:</strong> <?php echo htmlspecialchars($booking_data['passenger_name'] ?? $user_name); ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-envelope"></i></span>
                                    <strong>Email:</strong> <?php echo htmlspecialchars($booking_data['passenger_email'] ?? $user_email); ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-phone"></i></span>
                                    <strong>Phone:</strong> <?php echo isset($booking_data['passenger_phone']) ? htmlspecialchars($booking_data['passenger_phone']) : 'N/A'; ?>
                                </p>
                                <p>
                                    <span class="icon"><i class="fas fa-calendar-check"></i></span>
                                    <strong>Booking Date:</strong> <?php echo $created_at_formatted; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bus Details -->
                    <div class="bus-details">
                        <h5><i class="fas fa-bus me-2"></i>Bus Details</h5>
                        <div class="divider"></div>
                        <div class="row">
                            <div class="col-md-6">
                                <p>
                                    <strong>Bus ID:</strong> #<?php echo $bus_data['id'] ?? 'N/A'; ?>
                                </p>
                                <p>
                                    <strong>Bus Type:</strong> <?php echo $bus_data['bus_type'] ?? 'N/A'; ?>
                                </p>
                                <p>
                                    <strong>Plate Number:</strong> <?php echo htmlspecialchars($bus_data['plate_number'] ?? 'N/A'); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p>
                                    <strong>Driver:</strong> <?php echo htmlspecialchars($bus_data['driver_name'] ?? 'N/A'); ?>
                                </p>
                                <p>
                                    <strong>Conductor:</strong> <?php echo htmlspecialchars($bus_data['conductor_name'] ?? 'N/A'); ?>
                                </p>
                                <p>
                                    <strong>Status:</strong> 
                                    <span class="badge bg-success">Confirmed</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fare Details -->
                    <div class="fare-details">
                        <h5><i class="fas fa-money-bill-wave me-2"></i>Fare Details</h5>
                        <div class="divider"></div>
                        <div class="row">
                            <div class="col-md-8">
                                <p>
                                    <strong>Base Fare:</strong>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <p>₱<?php echo number_format($fare_amount, 2); ?></p>
                            </div>
                        </div>
                        <div class="divider"></div>
                        <div class="row">
                            <div class="col-md-8">
                                <p class="mb-0">
                                    <strong>Total Amount:</strong>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <p class="mb-0 fw-bold">₱<?php echo number_format($fare_amount, 2); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- QR Code and Barcode -->
                    <div class="text-center">
                        <!-- Display QR code as image (placeholder) -->
                        <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo urlencode($booking_data['booking_reference'] ?? 'INVALID'); ?>&size=100x100" alt="QR Code" class="qr-code">
                        
                        <!-- Display a placeholder barcode for printing -->
                        <img src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($booking_data['booking_reference'] ?? 'INVALID'); ?>&code=Code128&translate-esc=true" alt="Barcode" class="barcode">
                    </div>
                    
                    <!-- Receipt Footer -->
                    <div class="receipt-footer">
                        <p class="mb-1">This is a computer-generated receipt and does not require a signature.</p>
                        <p class="mb-1">Please present this receipt to the conductor upon boarding the bus.</p>
                        <p class="mb-0">Thank you for choosing Ceres Bus for your journey!</p>
                    </div>
                </div>
                
                <!-- Action buttons -->
                <div class="action-buttons d-flex justify-content-between mt-4">
                    <button onclick="window.location.href='../mybookings.php'" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to My Bookings
                    </button>
                    <button onclick="printReceipt()" class="btn btn-success">
                        <i class="fas fa-print me-2"></i>Print Receipt
                    </button>
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
                        <li><a href="../routes.php" class="text-white">Routes</a></li>
                        <li><a href="../schedule.php" class="text-white">Schedule</a></li>
                        <li><a href="../booking.php" class="text-white">Book Ticket</a></li>
                        <li><a href="../contact.php" class="text-white">Contact Us</a></li>
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

        function printReceipt() {
            // Set print-specific styles
            const originalTitle = document.title;
            document.title = "Ceres Bus Ticket System - " + "<?php echo htmlspecialchars($booking_data['booking_reference'] ?? 'RECEIPT'); ?>";
            
            // Print the page
            window.print();
            
            // Restore the original title
            setTimeout(function() {
                document.title = originalTitle;
            }, 500);
            
            return false;
        }
        
        // Replace onclick handler for print buttons
        document.querySelectorAll('button[onclick="window.print()"]').forEach(function(button) {
            button.onclick = printReceipt;
        });
        
        // Add keyboard shortcut for printing (Ctrl+P)
        document.addEventListener('keydown', function(e) {
            // Check if Ctrl+P is pressed
            if (e.ctrlKey && e.key === 'p') {
                // Prevent the default print dialog
                e.preventDefault();
                // Call our custom print function
                printReceipt();
            }
        });
    </script>
    </body>
</html>