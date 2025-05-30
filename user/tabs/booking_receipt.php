<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../../login.php");
    exit;
}

// Database connection
require_once "../../backend/connections/config.php";

$ticket_group_id = isset($_GET['ticket_group_id']) ? $_GET['ticket_group_id'] : '';

if (empty($ticket_group_id)) {
    header("Location: ../mybookings.php");
    exit;
}

// Fetch all bookings in this group
try {
    $query = "SELECT b.*, u.first_name, u.last_name, u.email, u.contact_number,
                     bus.bus_type, bus.plate_number, bus.driver_name, bus.conductor_name, bus.route_name,
                     r.origin, r.destination, r.distance, r.estimated_duration, r.fare as base_fare
              FROM bookings b
              JOIN users u ON b.user_id = u.id
              JOIN buses bus ON b.bus_id = bus.id
              LEFT JOIN routes r ON bus.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
              WHERE b.ticket_group_id = ?
              ORDER BY b.seat_number ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $ticket_group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: ../mybookings.php");
        exit;
    }
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    $first_booking = $bookings[0];
    
} catch (Exception $e) {
    error_log("Error fetching group booking: " . $e->getMessage());
    header("Location: ../mybookings.php");
    exit;
}

// Calculate total fare
$total_fare = 0;
$base_fare = $first_booking['base_fare'] ?? 0;
foreach ($bookings as $booking) {
    $ticket_fare = $base_fare;
    if ($booking['discount_type'] !== 'regular') {
        $ticket_fare = $base_fare * 0.8; // 20% discount
    }
    $total_fare += $ticket_fare;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Booking Receipt - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../../css/navfot.css">
    <style>
        .receipt-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .receipt-header::before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            clip-path: polygon(0 0, 100% 0, 95% 100%, 5% 100%);
        }
        
        .receipt-body {
            padding: 30px;
        }
        
        .ticket-group-info {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 5px solid #007bff;
        }
        
        .passenger-ticket {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            margin-bottom: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
            background: white;
        }
        
        .passenger-ticket:hover {
            border-color: #007bff;
            box-shadow: 0 5px 20px rgba(0,123,255,0.15);
            transform: translateY(-2px);
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .ticket-body {
            padding: 25px;
        }
        
        .seat-number-badge {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(0,123,255,0.3);
        }
        
        .status-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-confirmed {
            background: linear-gradient(135deg, #d1ecf1, #b8daff);
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .payment-status {
            font-size: 0.9rem;
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: 500;
        }
        
        .payment-verified {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .payment-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }
        
        .payment-awaiting {
            background: linear-gradient(135deg, #cce5ff, #b3d9ff);
            color: #004085;
        }
        
        .summary-section {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            border-left: 5px solid #28a745;
        }
        
        .qr-code-section {
            text-align: center;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 15px;
            margin: 25px 0;
            border: 2px dashed #007bff;
        }
        
        .instructions-box {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffeaa7;
            border-radius: 15px;
            padding: 25px;
            margin-top: 25px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .receipt-container {
                box-shadow: none;
                border: 1px solid #ddd;
                margin: 0;
                max-width: 100%;
            }
            
            body {
                background: white !important;
            }
            
            .passenger-ticket:hover {
                transform: none;
                box-shadow: none;
            }
        }
        
        .divider {
            height: 3px;
            background: linear-gradient(to right, transparent, #007bff, transparent);
            margin: 30px 0;
            border-radius: 2px;
        }
        
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .fare-display {
            background: linear-gradient(135deg, #e8f5e8, #d4edda);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin: 10px 0;
        }
        
        .discount-badge {
            background: linear-gradient(135deg, #ffc107, #ffb300);
            color: #000;
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .passenger-age-badge {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../mybookings.php">
                    <i class="fas fa-arrow-left me-1"></i>Back to My Bookings
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="receipt-container">
            <!-- Receipt Header -->
            <div class="receipt-header">
                <h2 class="mb-3">
                    <i class="fas fa-ticket-alt me-3"></i>
                    Group Booking Receipt
                </h2>
                <div class="row">
                    <div class="col-md-4">
                        <h5><i class="fas fa-users me-2"></i><?php echo count($bookings); ?> Ticket(s)</h5>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="fas fa-id-badge me-2"></i><?php echo htmlspecialchars($ticket_group_id); ?></h5>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="fas fa-calendar me-2"></i><?php echo date('M d, Y', strtotime($first_booking['created_at'])); ?></h5>
                    </div>
                </div>
            </div>

            <!-- Receipt Body -->
            <div class="receipt-body">
                <!-- Group Information -->
                <div class="ticket-group-info">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-user-circle me-2"></i>Booked By</h6>
                            <h5 class="mb-1 text-primary"><?php echo htmlspecialchars($first_booking['first_name'] . ' ' . $first_booking['last_name']); ?></h5>
                            <p class="mb-1 text-muted"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($first_booking['email']); ?></p>
                            <p class="mb-0 text-muted"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($first_booking['contact_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-route me-2"></i>Trip Details</h6>
                            <h5 class="mb-1 text-primary"><?php echo htmlspecialchars($first_booking['origin']); ?> to <?php echo htmlspecialchars($first_booking['destination']); ?></h5>
                            <p class="mb-1"><i class="fas fa-calendar-day me-1"></i>Date: <?php echo date('F d, Y', strtotime($first_booking['booking_date'])); ?></p>
                            <p class="mb-1"><i class="fas fa-bus me-1"></i>Trip: <?php echo htmlspecialchars($first_booking['trip_number']); ?></p>
                            <p class="mb-0"><i class="fas fa-id-card me-1"></i>Bus: <?php echo htmlspecialchars($first_booking['plate_number']); ?> (<?php echo htmlspecialchars($first_booking['bus_type']); ?>)</p>
                        </div>
                    </div>
                </div>

                <!-- Individual Tickets -->
                <h5 class="mb-4"><i class="fas fa-tickets-alt me-2"></i>Individual Tickets</h5>
                
                <?php foreach ($bookings as $index => $booking): ?>
                <div class="passenger-ticket">
                    <div class="ticket-header">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1">
                                    <i class="fas fa-user me-2"></i>
                                    <?php echo htmlspecialchars($booking['passenger_name'] ?? 'Passenger ' . ($index + 1)); ?>
                                    <?php if ($booking['passenger_age']): ?>
                                    <span class="passenger-age-badge"><?php echo $booking['passenger_age']; ?> yrs</span>
                                    <?php endif; ?>
                                </h6>
                                <?php if ($booking['discount_type'] !== 'regular'): ?>
                                <span class="discount-badge">
                                    <i class="fas fa-tag me-1"></i><?php echo ucfirst($booking['discount_type']); ?> Discount
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-center">
                                <span class="seat-number-badge">
                                    <i class="fas fa-chair me-1"></i>Seat <?php echo $booking['seat_number']; ?>
                                </span>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="fare-display">
                                    <?php 
                                    $ticket_fare = $base_fare;
                                    if ($booking['discount_type'] !== 'regular') {
                                        $original_fare = $base_fare;
                                        $ticket_fare = $base_fare * 0.8;
                                        echo '<small class="text-muted"><del>₱' . number_format($original_fare, 2) . '</del></small><br>';
                                    }
                                    ?>
                                    <strong class="text-success">₱<?php echo number_format($ticket_fare, 2); ?></strong>
                                </div>
                                <small class="text-muted">Ref: <?php echo htmlspecialchars($booking['booking_reference']); ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ticket-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-card">
                                    <h6><i class="fas fa-info-circle me-2 text-primary"></i>Booking Status</h6>
                                    <span class="status-badge status-<?php echo $booking['booking_status']; ?>">
                                        <i class="fas fa-<?php echo $booking['booking_status'] === 'confirmed' ? 'check-circle' : 'clock'; ?> me-1"></i>
                                        <?php echo ucfirst($booking['booking_status']); ?>
                                    </span>
                                    
                                    <h6 class="mt-3"><i class="fas fa-credit-card me-2 text-primary"></i>Payment Method</h6>
                                    <div class="mb-2">
                                        <?php 
                                        $paymentMethods = [
                                            'counter' => '<i class="fas fa-money-bill-wave me-1"></i>Over the Counter',
                                            'gcash' => '<i class="fas fa-mobile-alt me-1"></i>GCash',
                                            'paymaya' => '<i class="fas fa-credit-card me-1"></i>PayMaya'
                                        ];
                                        echo $paymentMethods[$booking['payment_method']] ?? ucfirst($booking['payment_method']);
                                        ?>
                                    </div>
                                    
                                    <span class="payment-status payment-<?php echo str_replace('_', '-', $booking['payment_status']); ?>">
                                        <?php 
                                        $statusIcons = [
                                            'verified' => 'check-circle',
                                            'pending' => 'clock',
                                            'awaiting_verification' => 'hourglass-half'
                                        ];
                                        $statusText = [
                                            'verified' => 'Verified',
                                            'pending' => 'Pending Payment',
                                            'awaiting_verification' => 'Awaiting Verification'
                                        ];
                                        $icon = $statusIcons[$booking['payment_status']] ?? 'question-circle';
                                        $text = $statusText[$booking['payment_status']] ?? ucfirst(str_replace('_', ' ', $booking['payment_status']));
                                        ?>
                                        <i class="fas fa-<?php echo $icon; ?> me-1"></i><?php echo $text; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="info-card">
                                    <h6><i class="fas fa-bus me-2 text-primary"></i>Bus Information</h6>
                                    <div class="mb-2">
                                        <strong><?php echo htmlspecialchars($booking['bus_type']); ?> Bus</strong>
                                        <br><small class="text-muted">Plate: <?php echo htmlspecialchars($booking['plate_number']); ?></small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <strong>Driver:</strong> <?php echo htmlspecialchars($booking['driver_name']); ?>
                                        <br><strong>Conductor:</strong> <?php echo htmlspecialchars($booking['conductor_name']); ?>
                                    </div>
                                    
                                    <?php if ($booking['distance'] && $booking['estimated_duration']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-road me-1"></i><?php echo $booking['distance']; ?> km • 
                                        <i class="fas fa-clock me-1"></i><?php echo $booking['estimated_duration']; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Summary Section -->
                <div class="summary-section">
                    <h5 class="mb-4"><i class="fas fa-calculator me-2"></i>Booking Summary</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong><i class="fas fa-users me-2"></i>Total Passengers:</strong> <?php echo count($bookings); ?>
                            </div>
                            <div class="mb-3">
                                <strong><i class="fas fa-chair me-2"></i>Selected Seats:</strong> 
                                <?php 
                                $seats = array_column($bookings, 'seat_number');
                                sort($seats);
                                echo implode(', ', $seats);
                                ?>
                            </div>
                            <div class="mb-3">
                                <strong><i class="fas fa-calendar-day me-2"></i>Travel Date:</strong> <?php echo date('F d, Y', strtotime($first_booking['booking_date'])); ?>
                            </div>
                            <div class="mb-3">
                                <strong><i class="fas fa-route me-2"></i>Route:</strong> 
                                <?php echo htmlspecialchars($first_booking['origin'] . ' → ' . $first_booking['destination']); ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong><i class="fas fa-money-bill-wave me-2"></i>Base Fare per ticket:</strong> ₱<?php echo number_format($base_fare, 2); ?>
                            </div>
                            <?php 
                            $discounted_tickets = 0;
                            foreach ($bookings as $booking) {
                                if ($booking['discount_type'] !== 'regular') {
                                    $discounted_tickets++;
                                }
                            }
                            if ($discounted_tickets > 0): ?>
                            <div class="mb-3">
                                <strong><i class="fas fa-tag me-2"></i>Discounted Tickets:</strong> <?php echo $discounted_tickets; ?> 
                                <span class="small text-muted">(20% off)</span>
                            </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <strong><i class="fas fa-receipt me-2"></i>Total Amount:</strong> 
                                <span class="text-success fs-4">₱<?php echo number_format($total_fare, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- QR Code Section -->
                <div class="qr-code-section">
                    <h6><i class="fas fa-qrcode me-2"></i>Group Booking QR Code</h6>
                    <div class="qr-placeholder bg-light border rounded p-4 d-inline-block">
                        <i class="fas fa-qrcode fa-5x text-muted"></i>
                        <div class="mt-2 small text-muted">Scan for quick verification</div>
                        <div class="small text-muted fw-bold"><?php echo htmlspecialchars($ticket_group_id); ?></div>
                    </div>
                    <p class="mt-2 mb-0 small text-muted">Present this QR code during check-in for faster processing</p>
                </div>

                <!-- Important Instructions -->
                <div class="instructions-box">
                    <h6><i class="fas fa-info-circle me-2"></i>Important Instructions</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="mb-0">
                                <li><strong>Arrival Time:</strong> Please arrive at the terminal at least 30 minutes before departure.</li>
                                <li><strong>Valid ID:</strong> Each passenger must present a valid ID that matches the name on their ticket.</li>
                                <li><strong>Group Booking:</strong> All passengers in this group booking should travel together.</li>
                                <?php if ($first_booking['payment_method'] !== 'counter'): ?>
                                <li><strong>Payment Verification:</strong> Ensure your payment has been verified before boarding. Check your booking status.</li>
                                <?php else: ?>
                                <li><strong>Payment:</strong> Pay the total amount (₱<?php echo number_format($total_fare, 2); ?>) at the counter using this receipt as reference.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="mb-0">
                                <li><strong>Cancellation:</strong> Group bookings can be cancelled up to 24 hours before departure.</li>
                                <li><strong>Contact:</strong> For any concerns, contact the terminal at (033) 337-8888.</li>
                                <li><strong>Lost Receipt:</strong> Keep this receipt safe. Present the QR code if receipt is lost.</li>
                                <li><strong>Changes:</strong> Seat changes are subject to availability and may incur additional charges.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Action Buttons -->
                <div class="text-center no-print">
                    <button class="btn btn-primary btn-lg me-3" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Receipt
                    </button>
                    <a href="../mybookings.php" class="btn btn-outline-secondary btn-lg me-3">
                        <i class="fas fa-list me-2"></i>View All Bookings
                    </a>
                    <button class="btn btn-info btn-lg" onclick="downloadReceipt()">
                        <i class="fas fa-download me-2"></i>Download PDF
                    </button>
                </div>

                <!-- Contact Information -->
                <div class="text-center mt-5 pt-4 border-top">
                    <h6 class="text-primary"><strong>Ceres Bus Terminal</strong></h6>
                    <p class="mb-1 text-muted">Iloilo City Terminal • Phone: (033) 337-8888</p>
                    <p class="mb-0 text-muted">Email: isatucommuters@ceresbus.com</p>
                    <p class="small text-muted mt-2">Thank you for choosing Ceres Bus for ISAT-U Commuters!</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <div class="container mt-4 no-print">
        <div class="alert alert-success text-center">
            <h5 class="alert-heading">
                <i class="fas fa-check-circle me-2"></i>Group Booking Confirmed!
            </h5>
            <p class="mb-0">
                Your group booking for <?php echo count($bookings); ?> passenger(s) has been successfully created. 
                Please save this receipt and present it during check-in. Total fare: <strong>₱<?php echo number_format($total_fare, 2); ?></strong>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to download receipt as PDF (placeholder)
        function downloadReceipt() {
            // This would typically integrate with a PDF generation library
            alert('PDF download feature will be implemented with a PDF generation library like jsPDF or server-side PDF generation.');
            
            // Alternative: Open print dialog which allows saving as PDF
            window.print();
        }

        // Auto-print on load if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === 'true') {
            setTimeout(() => {
                window.print();
            }, 1000);
        }

        // Enhanced print styling
        window.addEventListener('beforeprint', function() {
            document.body.style.background = 'white';
        });

        // Animate elements on load
        document.addEventListener('DOMContentLoaded', function() {
            const tickets = document.querySelectorAll('.passenger-ticket');
            tickets.forEach((ticket, index) => {
                setTimeout(() => {
                    ticket.style.opacity = '0';
                    ticket.style.transform = 'translateY(20px)';
                    ticket.style.transition = 'all 0.5s ease';
                    
                    setTimeout(() => {
                        ticket.style.opacity = '1';
                        ticket.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 150);
            });
        });

        // Copy group ID to clipboard
        function copyGroupId() {
            const groupId = '<?php echo $ticket_group_id; ?>';
            navigator.clipboard.writeText(groupId).then(function() {
                // Show toast notification
                const toast = document.createElement('div');
                toast.className = 'alert alert-info position-fixed';
                toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 250px;';
                toast.innerHTML = '<i class="fas fa-copy me-2"></i>Group ID copied to clipboard!';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.remove();
                }, 3000);
            });
        }

        // Add click handler to group ID
        document.addEventListener('DOMContentLoaded', function() {
            const groupIdElement = document.querySelector('.receipt-header h5');
            if (groupIdElement) {
                groupIdElement.style.cursor = 'pointer';
                groupIdElement.title = 'Click to copy Group ID';
                groupIdElement.addEventListener('click', copyGroupId);
            }
        });

        // Refresh page to check payment status
        function refreshPaymentStatus() {
            location.reload();
        }

        // Auto-refresh every 30 seconds if there are pending payments
        <?php 
        $hasPendingPayments = false;
        foreach ($bookings as $booking) {
            if ($booking['payment_status'] === 'awaiting_verification') {
                $hasPendingPayments = true;
                break;
            }
        }
        if ($hasPendingPayments): ?>
        setInterval(refreshPaymentStatus, 30000);
        
        // Show notification about pending payments
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.createElement('div');
            notification.className = 'alert alert-warning position-fixed';
            notification.style.cssText = 'bottom: 20px; right: 20px; z-index: 9999; max-width: 350px;';
            notification.innerHTML = `
                <i class="fas fa-hourglass-half me-2"></i>
                <strong>Payment Verification Pending</strong><br>
                Your payment is being verified. This page will auto-refresh every 30 seconds.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(notification);
        });
        <?php endif; ?>

        // Enhanced animations and interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to passenger tickets
            const passengerTickets = document.querySelectorAll('.passenger-ticket');
            passengerTickets.forEach(ticket => {
                ticket.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                
                ticket.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Add click-to-expand functionality for ticket details
            passengerTickets.forEach(ticket => {
                const header = ticket.querySelector('.ticket-header');
                const body = ticket.querySelector('.ticket-body');
                
                header.addEventListener('click', function() {
                    body.style.transition = 'max-height 0.3s ease';
                    if (body.style.maxHeight === '0px' || !body.style.maxHeight) {
                        body.style.maxHeight = body.scrollHeight + 'px';
                        header.style.cursor = 'pointer';
                    } else {
                        body.style.maxHeight = '0px';
                    }
                });
            });

            // Add print preview functionality
            const printBtn = document.querySelector('button[onclick="window.print()"]');
            if (printBtn) {
                printBtn.addEventListener('mouseenter', function() {
                    document.body.classList.add('print-preview');
                });
                
                printBtn.addEventListener('mouseleave', function() {
                    document.body.classList.remove('print-preview');
                });
            }
        });

        // Share receipt functionality
        function shareReceipt() {
            if (navigator.share) {
                navigator.share({
                    title: 'Group Booking Receipt',
                    text: `Group booking receipt for ${<?php echo count($bookings); ?>} passengers - Total: ₱${<?php echo number_format($total_fare, 2); ?>}`,
                    url: window.location.href
                });
            } else {
                // Fallback: copy URL to clipboard
                navigator.clipboard.writeText(window.location.href).then(function() {
                    alert('Receipt URL copied to clipboard!');
                });
            }
        }

        // Add email receipt functionality
        function emailReceipt() {
            const subject = encodeURIComponent('Group Booking Receipt - ' + '<?php echo $ticket_group_id; ?>');
            const body = encodeURIComponent(`
                Group Booking Receipt
                
                Group ID: <?php echo $ticket_group_id; ?>
                Passengers: <?php echo count($bookings); ?>
                Route: <?php echo $first_booking['origin'] . ' to ' . $first_booking['destination']; ?>
                Date: <?php echo date('F d, Y', strtotime($first_booking['booking_date'])); ?>
                Total Fare: ₱<?php echo number_format($total_fare, 2); ?>
                
                View full receipt: ${window.location.href}
                
                Thank you for choosing Ceres Bus!
            `);
            
            window.location.href = `mailto:?subject=${subject}&body=${body}`;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl+S for download/save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                downloadReceipt();
            }
        });

        // Add loading states for buttons
        function addLoadingState(button, originalText) {
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status"></span>Loading...`;
            
            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 2000);
        }

        // Enhanced print functionality with loading state
        const printButton = document.querySelector('button[onclick="window.print()"]');
        if (printButton) {
            printButton.addEventListener('click', function(e) {
                e.preventDefault();
                const originalText = this.innerHTML;
                addLoadingState(this, originalText);
                
                setTimeout(() => {
                    window.print();
                }, 500);
            });
        }

        // Performance optimization: lazy load images if any
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    </script>

    <!-- Additional CSS for enhanced interactions -->
    <style>
        .print-preview {
            filter: grayscale(20%);
        }
        
        .passenger-ticket {
            cursor: pointer;
        }
        
        .passenger-ticket .ticket-header:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
        }
        
        .lazy {
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .lazy.loaded {
            opacity: 1;
        }
        
        /* Additional responsive improvements */
        @media (max-width: 768px) {
            .receipt-container {
                margin: 10px;
                border-radius: 10px;
            }
            
            .receipt-header {
                padding: 20px;
            }
            
            .receipt-body {
                padding: 20px;
            }
            
            .passenger-ticket {
                margin-bottom: 15px;
            }
            
            .ticket-header {
                padding: 15px;
            }
            
            .ticket-body {
                padding: 15px;
            }
            
            .btn-lg {
                padding: 8px 16px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 480px) {
            .seat-number-badge {
                font-size: 1rem;
                padding: 8px 15px;
            }
            
            .receipt-header h2 {
                font-size: 1.5rem;
            }
            
            .receipt-header h5 {
                font-size: 1rem;
            }
        }
    </style>
</body>
</html>