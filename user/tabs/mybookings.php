<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Redirect to login page
    header("Location: ../../login.php");
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

// Process booking cancellation if requested
if (isset($_POST['cancel_booking']) && isset($_POST['booking_id'])) {
    $booking_id = $_POST['booking_id'];
    
    try {
        // Verify the booking belongs to the current user
        $verify_stmt = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND user_id = ?");
        $verify_stmt->bind_param("ii", $booking_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 1) {
            // Update booking status to cancelled
            $cancel_stmt = $conn->prepare("UPDATE bookings SET booking_status = 'cancelled' WHERE id = ?");
            $cancel_stmt->bind_param("i", $booking_id);
            
            if ($cancel_stmt->execute()) {
                $success_message = "Your booking has been successfully cancelled.";
            } else {
                $error_message = "Failed to cancel booking. Please try again.";
            }
            $cancel_stmt->close();
        } else {
            $error_message = "Invalid booking or you don't have permission to cancel this booking.";
        }
        $verify_stmt->close();
    } catch (Exception $e) {
        $error_message = "An error occurred: " . $e->getMessage();
        error_log("Error cancelling booking: " . $e->getMessage());
    }
}

// Process booking cancellation with reason
if (isset($_POST['cancel_booking_with_reason']) && isset($_POST['booking_id'])) {
    $booking_id = $_POST['booking_id'];
    $cancel_reason = isset($_POST['cancel_reason']) ? $_POST['cancel_reason'] : 'No reason provided';
    
    // If "Other" reason is selected, use the text from other_reason field
    if ($cancel_reason === 'Other' && isset($_POST['other_reason']) && !empty($_POST['other_reason'])) {
        $cancel_reason = 'Other: ' . $_POST['other_reason'];
    }
    
    try {
        // Verify the booking belongs to the current user and get fare from routes table
        $verify_stmt = $conn->prepare("
            SELECT 
                b.id, 
                b.booking_status, 
                b.created_at, 
                b.booking_date, 
                r.fare
            FROM 
                bookings b
            JOIN 
                buses bs ON b.bus_id = bs.id
            JOIN 
                routes r ON bs.route_id = r.id
            WHERE 
                b.id = ? AND b.user_id = ?
        ");
        $verify_stmt->bind_param("ii", $booking_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $booking_data = $verify_result->fetch_assoc();
        
        if ($verify_result->num_rows === 1) {
            // Check if already cancelled
            if ($booking_data['booking_status'] === 'cancelled') {
                $error_message = "This booking is already cancelled.";
            } else {
                // Calculate refund amount based on policy
                $refund_amount = $booking_data['fare'];
                $refund_status = 'pending';
                $refund_note = "Full refund";

                // Get current date and time
                $now = new DateTime();
                $bookingDateTime = new DateTime($booking_data['booking_date']);
                $createdDateTime = new DateTime($booking_data['created_at']);
                
                // Calculate hours until departure
                $departureInterval = $now->diff($bookingDateTime);
                $hoursTillDeparture = ($departureInterval->days * 24) + $departureInterval->h;
                
                // Calculate hours since booking was created
                $creationInterval = $now->diff($createdDateTime);
                $hoursSinceCreation = ($creationInterval->days * 24) + $creationInterval->h + 
                                     ($creationInterval->i / 60);
                
                // Apply refund policy
                if ($hoursSinceCreation <= 1) {
                    // Within 1 hour of booking - full refund (grace period)
                    $refund_amount = $booking_data['fare'];
                    $refund_note = "Full refund (1-hour grace period)";
                } elseif ($hoursTillDeparture >= 48) {
                    // More than 48 hours before departure - full refund
                    $refund_amount = $booking_data['fare'];
                    $refund_note = "Full refund (more than 48 hours before departure)";
                } elseif ($hoursTillDeparture >= 24) {
                    // Between 24-48 hours before departure - 50% refund
                    $refund_amount = $booking_data['fare'] * 0.5;
                    $refund_note = "50% refund (24-48 hours before departure)";
                } else {
                    // Less than 24 hours before departure - no refund
                    $refund_amount = 0;
                    $refund_status = 'denied';
                    $refund_note = "No refund (less than 24 hours before departure)";
                }
                
                // Update booking status to cancelled and store cancellation details
                $cancel_stmt = $conn->prepare("UPDATE bookings SET 
                                            booking_status = 'cancelled', 
                                            cancel_reason = ?, 
                                            cancelled_at = NOW(),
                                            refund_status = ?,
                                            refund_amount = ?,
                                            refund_note = ?
                                            WHERE id = ?");
                $cancel_stmt->bind_param("ssdsi", $cancel_reason, $refund_status, $refund_amount, $refund_note, $booking_id);

                if ($cancel_stmt->execute()) {
                    $success_message = "Your booking has been successfully cancelled. A refund of ₱" . number_format($refund_amount, 2) . " will be processed.";
                } else {
                    $error_message = "Failed to cancel booking. Please try again.";
                }
                $cancel_stmt->close();
            }
        } else {
            $error_message = "Invalid booking or you don't have permission to cancel this booking.";
        }
        $verify_stmt->close();
    } catch (Exception $e) {
        $error_message = "An error occurred: " . $e->getMessage();
        error_log("Error cancelling booking: " . $e->getMessage());
    }
}

// Add a function to check cancellation policy
function canCancelBooking($bookingDate, $createdAt) {
    return true;
}

// Fetch booking data for the current user with related information
$bookings = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT
            b.id, 
            b.bus_id, 
            b.seat_number, 
            b.booking_date, 
            b.booking_status, 
            b.created_at,
            b.trip_number,
            b.payment_status,
            b.payment_method,
            bs.bus_type, 
            bs.plate_number,
            r.origin, 
            r.destination, 
            r.fare,
            s.departure_time, 
            s.arrival_time
        FROM 
            bookings b
        JOIN 
            buses bs ON b.bus_id = bs.id
        JOIN 
            routes r ON bs.route_id = r.id
        LEFT JOIN
            schedules s ON b.bus_id = s.bus_id AND r.origin = s.origin AND r.destination = s.destination
        WHERE 
            b.user_id = ?
        GROUP BY
            b.id
        ORDER BY 
            b.booking_date DESC, 
            b.created_at DESC
    ");
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
        
        $stmt->close();
    }
} catch (Exception $e) {
    $error_message = "Error fetching booking data: " . $e->getMessage();
    error_log($error_message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/user.css" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">
    <style>
        .text-muted {
            color: white !important;
        }
        .user-profile {
            text-align: center;
        }

        .user-info p {
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        .booking-card {
            border-radius: 10px;
            transition: transform 0.3s;
            margin-bottom: 1.5rem;
        }
        
        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .booking-header {
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding-bottom: 10px;
        }
        
        .booking-badge {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
        }
        
        .booking-details {
            font-size: 0.95rem;
        }
        
        .booking-details .row {
            margin-bottom: 0.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        .booking-actions {
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
        }
        
        .route-info {
            display: flex;
            align-items: center;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .route-arrow {
            margin: 0 10px;
            color: #aaa;
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
                        <a class="nav-link" href="booking.php">Book Ticket</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-wide">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3 sidebar">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Account Menu</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="../dashboard.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="profile.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a>
                            <a href="mybookings.php" class="list-group-item list-group-item-action active">
                                <i class="fas fa-ticket-alt me-2"></i>My Bookings
                            </a>
                            <a href="booking.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-plus-circle me-2"></i>Book New Ticket
                            </a>
                            <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Account Info</h5>
                    </div>
                    <div class="card-body">
                        <div class="user-profile">
                            <div class="user-avatar">
                                <i class="fas fa-user fa-2x text-light"></i>
                            </div>
                            <div class="user-info">
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($user_name); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($user_email); ?></p>
                                <p class="mb-0"><strong>Account Type:</strong> Commuter</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>My Bookings</h5>
                        <a href="booking.php" class="btn btn-warning btn-sm">
                            <i class="fas fa-plus-circle me-1"></i>Book New Ticket
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success" role="alert">
                                <?php echo $success_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($bookings)): ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt fa-3x mb-3"></i>
                                <h4>No Bookings Found</h4>
                                <p class="text-muted">You haven't made any bookings yet.</p>
                                <a href="booking.php" class="btn btn-warning mt-2">Book Your First Trip</a>
                            </div>
                        <?php else: ?>
                            <div class="booking-filters mb-3">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-secondary active filter-btn" data-filter="all">All</button>
                                    <button type="button" class="btn btn-outline-success filter-btn" data-filter="confirmed">Confirmed</button>
                                    <button type="button" class="btn btn-outline-danger filter-btn" data-filter="cancelled">Cancelled</button>
                                </div>
                            </div>

                            <?php foreach ($bookings as $booking): ?>
                                <div class="booking-card card booking-item" data-status="<?php echo $booking['booking_status']; ?>">
                                    <div class="card-header booking-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="fw-bold">Booking #<?php echo $booking['id']; ?></span>
                                            <span class="text-muted ms-2">(<?php echo date('M d, Y', strtotime($booking['created_at'])); ?>)</span>
                                        </div>
                                        <?php
                                        $badge_class = '';
                                        switch ($booking['booking_status']) {
                                            case 'confirmed':
                                                $badge_class = 'bg-success';
                                                break;
                                            case 'cancelled':
                                                $badge_class = 'bg-danger';
                                                break;
                                        }
                                        ?>
                                        <span class="badge booking-badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="booking-details">
                                            <div class="route-info">
                                                <span><?php echo htmlspecialchars(ucfirst($booking['origin'])); ?></span>
                                                <span class="route-arrow"><i class="fas fa-long-arrow-alt-right"></i></span>
                                                <span><?php echo htmlspecialchars(ucfirst($booking['destination'])); ?></span>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><i class="fas fa-hashtag me-2"></i><strong>Trip Number:</strong> <?php echo htmlspecialchars($booking['trip_number'] ?: 'N/A'); ?></p>
                                                    <p><i class="fas fa-calendar-alt me-2"></i><strong>Travel Date:</strong> <?php echo date('F d, Y', strtotime($booking['booking_date'])); ?></p>
                                                    <p><i class="fas fa-clock me-2"></i><strong>Departure:</strong> 
                                                        <?php echo $booking['departure_time'] ? date('h:i A', strtotime($booking['departure_time'])) : 'Check schedule'; ?>
                                                    </p>
                                                    <p><i class="fas fa-clock me-2"></i><strong>Arrival:</strong> 
                                                        <?php echo $booking['arrival_time'] ? date('h:i A', strtotime($booking['arrival_time'])) : 'Check schedule'; ?>
                                                    </p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><i class="fas fa-bus me-2"></i><strong>Bus Type:</strong> <?php echo htmlspecialchars($booking['bus_type']); ?></p>
                                                    <p><i class="fas fa-id-card me-2"></i><strong>Plate #:</strong> <?php echo htmlspecialchars($booking['plate_number']); ?></p>
                                                    <p><i class="fas fa-chair me-2"></i><strong>Seat #:</strong> <?php echo $booking['seat_number']; ?></p>
                                                    <p><i class="fas fa-money-bill-wave me-2"></i><strong>Fare:</strong> ₱<?php echo number_format($booking['fare'], 2); ?></p>
                                                    <p><i class="fas fa-credit-card me-2"></i><strong>Payment Method:</strong> <?php echo htmlspecialchars(ucfirst($booking['payment_method'] ?: 'N/A')); ?></p>
                                                    <p>
                                                        <i class="fas fa-check-circle me-2"></i><strong>Payment Status:</strong> 
                                                        <?php 
                                                        // Fix the misspelled status
                                                        $payment_status = $booking['payment_status'];
                                                        if ($payment_status === 'awaiting_verificatio') {
                                                            $payment_status = 'awaiting_verification';
                                                        }
                                                        
                                                        $payment_status_class = '';
                                                        switch (strtolower($payment_status)) {
                                                            case 'paid':
                                                                $payment_status_class = 'text-success';
                                                                break;
                                                            case 'pending':
                                                                $payment_status_class = 'text-warning';
                                                                break;
                                                            case 'awaiting_verification':
                                                                $payment_status_class = 'text-info';
                                                                break;
                                                            case 'failed':
                                                                $payment_status_class = 'text-danger';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="<?php echo $payment_status_class; ?>">
                                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment_status ?: 'N/A'))); ?>
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>

                                            <?php if ($booking['booking_status'] !== 'cancelled'): ?>
                                                <div class="booking-actions">
                                                    <button type="button" class="btn btn-outline-danger btn-sm cancel-booking-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#cancelModal<?php echo $booking['id']; ?>">
                                                        <i class="fas fa-times-circle me-1"></i> Cancel Booking
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($bookings as $booking): ?>
        <div class="modal fade" id="cancelModal<?php echo $booking['id']; ?>" tabindex="-1" 
            aria-labelledby="cancelModalLabel<?php echo $booking['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header bg-danger text-white border-bottom border-secondary">
                        <h5 class="modal-title" id="cancelModalLabel<?php echo $booking['id']; ?>">
                            <i class="fas fa-times-circle me-2"></i>Cancel Booking #<?php echo $booking['id']; ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body bg-dark">
                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <div class="card bg-dark border border-secondary shadow">
                                        <div class="card-body">
                                            <h6 class="card-title border-bottom border-secondary pb-2 text-warning">Booking Details</h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong class="text-warning">Trip:</strong> <?php echo htmlspecialchars(ucfirst($booking['origin'])); ?> to <?php echo htmlspecialchars(ucfirst($booking['destination'])); ?></p>
                                                    <p><strong class="text-warning">Date:</strong> <?php echo date('F d, Y', strtotime($booking['booking_date'])); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong class="text-warning">Bus Type:</strong> <?php echo htmlspecialchars($booking['bus_type']); ?></p>
                                                    <p><strong class="text-warning">Fare:</strong> ₱<?php echo number_format($booking['fare'], 2); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning" style="background-color: #2c2a1e; border-color: #665e33; color: #ffc107; border-radius: 8px;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-exclamation-triangle me-2" style="font-size: 1.2rem;"></i>
                                    <strong>Please note:</strong>
                                </div>
                                <ul class="mb-0 ps-4">
                                    <li class="mb-2">Cancellation is available anytime before your trip</li>
                                    <li>Cancelled bookings can't be reinstated</li>
                                </ul>
                            </div>
                            
                            <div class="card bg-dark border border-secondary shadow mt-4">
                                <div class="card-body">
                                    <h6 class="card-title border-bottom border-secondary pb-2 text-warning">Reason for Cancellation</h6>
                                    <div class="mb-3 mt-3">
                                        <select class="form-select form-select-lg bg-dark text-light border-secondary" 
                                                id="cancel_reason<?php echo $booking['id']; ?>" 
                                                name="cancel_reason" required>
                                            <option value="">Select a reason</option>
                                            <option value="Change of plans">Change of plans</option>
                                            <option value="Booking error">Booking error</option>
                                            <option value="Found alternative transportation">Found alternative transportation</option>
                                            <option value="Schedule conflict">Schedule conflict</option>
                                            <option value="Weather concerns">Weather concerns</option>
                                            <option value="Health issues">Health issues</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3" id="otherReasonDiv<?php echo $booking['id']; ?>" style="display: none;">
                                        <textarea class="form-control bg-dark text-light border-secondary" 
                                                id="other_reason<?php echo $booking['id']; ?>" 
                                                name="other_reason" rows="3" 
                                                placeholder="Please specify your reason"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                        </div>
                        <div class="modal-footer bg-dark border-top border-secondary">
                            <button type="button" class="btn btn-outline-light btn-lg" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i> Close
                            </button>
                            <button type="submit" name="cancel_booking_with_reason" class="btn btn-danger btn-lg">
                                <i class="fas fa-check-circle me-1"></i> Confirm Cancellation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="routes.php" class="text-white"><i class="fas fa-route me-2"></i>Routes</a></li>
                        <li><a href="schedule.php" class="text-white"><i class="fas fa-calendar-alt me-2"></i>Schedule</a></li>
                        <li><a href="booking.php" class="text-white"><i class="fas fa-ticket-alt me-2"></i>Book Ticket</a></li>
                        <li><a href="../contact.php" class="text-white"><i class="fas fa-envelope me-2"></i>Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
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
                <p class="copyright">&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Make sure this comes after jQuery and before your other scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            if (typeof bootstrap !== 'undefined') {
                console.log("Bootstrap is loaded correctly");
                
                // Initialize modals directly
                document.querySelectorAll('.modal').forEach(function(modalEl) {
                    var modal = new bootstrap.Modal(modalEl);
                });
                
                // Attach manual click handlers to the cancel buttons
                document.querySelectorAll('.cancel-booking-btn').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var targetModalId = this.getAttribute('data-bs-target');
                        var modalElement = document.querySelector(targetModalId);
                        
                        if (modalElement) {
                            var modal = new bootstrap.Modal(modalElement);
                            modal.show();
                        } else {
                            console.error("Modal element not found:", targetModalId);
                        }
                    });
                });
            } else {
                console.error("Bootstrap is not loaded properly. Check your script includes.");
            }
            
            // Filter bookings by status
            const filterButtons = document.querySelectorAll('.filter-btn');
            const bookingItems = document.querySelectorAll('.booking-item');
            
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const filterValue = this.getAttribute('data-filter');
                    
                    // Update active button
                    filterButtons.forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // Filter items
                    bookingItems.forEach(item => {
                        if (filterValue === 'all' || item.getAttribute('data-status') === filterValue) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            });
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Show/hide "other reason" text area when "Other" is selected
            const reasonSelects = document.querySelectorAll('select[name="cancel_reason"]');
            reasonSelects.forEach(select => {
                select.addEventListener('change', function() {
                    const bookingId = this.id.replace('cancel_reason', '');
                    const otherReasonDiv = document.getElementById('otherReasonDiv' + bookingId);
                    
                    if (this.value === 'Other') {
                        otherReasonDiv.style.display = 'block';
                        document.getElementById('other_reason' + bookingId).setAttribute('required', 'required');
                    } else {
                        otherReasonDiv.style.display = 'none';
                        document.getElementById('other_reason' + bookingId).removeAttribute('required');
                    }
                });
            });
            
            // Custom form validation for cancellation
            const cancelForms = document.querySelectorAll('form');
            cancelForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (this.querySelector('select[name="cancel_reason"]')) {
                        const reasonSelect = this.querySelector('select[name="cancel_reason"]');
                        const otherReason = this.querySelector('textarea[name="other_reason"]');
                        
                        if (reasonSelect.value === '') {
                            e.preventDefault();
                            alert('Please select a reason for cancellation');
                            return false;
                        }
                        
                        if (reasonSelect.value === 'Other' && otherReason && otherReason.value.trim() === '') {
                            e.preventDefault();
                            alert('Please specify your reason for cancellation');
                            return false;
                        }
                        
                        if (!confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    return true;
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Show/hide other reason field when "Other" is selected
            document.querySelectorAll('select[name="cancel_reason"]').forEach(select => {
                select.addEventListener('change', function() {
                    const bookingId = this.id.replace('cancel_reason', '');
                    const otherReasonDiv = document.getElementById('otherReasonDiv' + bookingId);
                    
                    if (this.value === 'Other') {
                        otherReasonDiv.style.display = 'block';
                        document.getElementById('other_reason' + bookingId).setAttribute('required', 'required');
                    } else {
                        otherReasonDiv.style.display = 'none';
                        document.getElementById('other_reason' + bookingId).removeAttribute('required');
                    }
                });
            });
    </script>
</body>
</html>