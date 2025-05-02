<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once "../../backend/connections/config.php";

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Initialize filter variables
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_method_filter = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build the SQL query with filters
$sql_conditions = [];
$sql_params = [];

// Make sure your base query includes the discount_id_proof field:
$base_query = "SELECT b.id, b.booking_reference, b.payment_method, b.payment_status, b.payment_proof_status, 
               b.payment_proof, b.payment_proof_timestamp, b.created_at, 
               u.first_name, u.last_name, b.trip_number, 
               r.origin, r.destination, r.fare, b.discount_type, b.discount_id_proof, b.seat_number 
               FROM bookings b 
               JOIN users u ON b.user_id = u.id 
               JOIN buses bus ON b.bus_id = bus.id 
               LEFT JOIN routes r ON bus.route_id = r.id";

// Add filters
if (!empty($status_filter)) {
    $sql_conditions[] = "b.payment_status = ?";
    $sql_params[] = $status_filter;
}

if (!empty($payment_method_filter)) {
    $sql_conditions[] = "b.payment_method = ?";
    $sql_params[] = $payment_method_filter;
}

if (!empty($date_from)) {
    $sql_conditions[] = "DATE(b.created_at) >= ?";
    $sql_params[] = $date_from;
}

if (!empty($date_to)) {
    $sql_conditions[] = "DATE(b.created_at) <= ?";
    $sql_params[] = $date_to;
}

if (!empty($search)) {
    $sql_conditions[] = "(b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $search_param = "%$search%";
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
}

// Build the final query
$query = $base_query;
if (!empty($sql_conditions)) {
    $query .= " WHERE " . implode(" AND ", $sql_conditions);
}
$query .= " ORDER BY b.created_at DESC LIMIT ?, ?";
$sql_params[] = $offset;
$sql_params[] = $records_per_page;

// Count total records (for pagination)
$count_query = "SELECT COUNT(*) as total FROM bookings b JOIN users u ON b.user_id = u.id";
if (!empty($sql_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $sql_conditions);
}

// Process payment verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    
    if ($_POST['action'] === 'approve_payment') {
        // Update payment status to verified
        $update_query = "UPDATE bookings SET payment_status = 'verified', payment_proof_status = 'verified' WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $booking_id);
        
        if ($stmt->execute()) {
            // Also update booking status to confirmed
            $update_booking = "UPDATE bookings SET booking_status = 'confirmed' WHERE id = ?";
            $stmt_booking = $conn->prepare($update_booking);
            $stmt_booking->bind_param("i", $booking_id);
            $stmt_booking->execute();
        //    
        //    // Create notification
        //    $create_notification = "INSERT INTO notifications (title, message, type, related_id, admin_read) 
        //                          VALUES ('Payment Verified', 'Payment for booking #$booking_id has been verified and approved', 'payment', ?, 0)";
        //    $stmt_notif = $conn->prepare($create_notification);
        //    $stmt_notif->bind_param("i", $booking_id);
        //    $stmt_notif->execute();
        //    
            $_SESSION['success_message'] = "Payment has been verified and approved successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to verify payment.";
        } 
        
        // Redirect to prevent resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
        exit;
    } elseif ($_POST['action'] === 'reject_payment') {
        // Update payment status to rejected
        $update_query = "UPDATE bookings SET payment_status = 'rejected', payment_proof_status = 'rejected' WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $booking_id);
        
        if ($stmt->execute()) {
        //    // Create notification
        //    $create_notification = "INSERT INTO notifications (title, message, type, related_id, admin_read) 
        //                          VALUES ('Payment Rejected', 'Payment for booking #$booking_id has been rejected. Please upload a valid payment proof.', 'payment', ?, 0)";
        //    $stmt_notif = $conn->prepare($create_notification);
        //    $stmt_notif->bind_param("i", $booking_id);
        //    $stmt_notif->execute();
        //    
            $_SESSION['success_message'] = "Payment has been rejected.";
        } else {
            $_SESSION['error_message'] = "Failed to reject payment.";
        }
        
    } elseif ($_POST['action'] === 'mark_counter_payment') {
            // Update payment status to verified and method to counter
            $update_query = "UPDATE bookings SET payment_status = 'verified', payment_method = 'counter', payment_proof_status = 'verified' WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("i", $booking_id);
            
            if ($stmt->execute()) {
                // Also update booking status to confirmed
                $update_booking = "UPDATE bookings SET booking_status = 'confirmed' WHERE id = ?";
                $stmt_booking = $conn->prepare($update_booking);
                $stmt_booking->bind_param("i", $booking_id);
                $stmt_booking->execute();
                
            //    // Create notification
            //    $create_notification = "INSERT INTO notifications (title, message, type, related_id, admin_read) 
            //                          VALUES ('Counter Payment Processed', 'Payment for booking #$booking_id has been processed at the counter', 'payment', ?, 0)";
            //    $stmt_notif = $conn->prepare($create_notification);
            //    $stmt_notif->bind_param("i", $booking_id);
            //    $stmt_notif->execute();
                
                $_SESSION['success_message'] = "Counter payment has been recorded successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to process counter payment.";
            }
            
            // Redirect to prevent resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
            exit;
        }
        elseif ($_POST['action'] === 'verify_discount') {
            // Update discount verification status
            $update_query = "UPDATE bookings SET discount_verified = 1 WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("i", $booking_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Discount ID has been verified successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to verify discount ID.";
            }
            
            // Redirect to prevent resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
            exit;
        }
}

// Prepare and execute the main query
$stmt = $conn->prepare($query);
if (!empty($sql_params)) {
    $types = str_repeat("s", count($sql_params) - 2) . "ii"; // All strings except for LIMIT params which are integers
    $stmt->bind_param($types, ...$sql_params);
}
$stmt->execute();
$result = $stmt->get_result();
$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}

// Count total records for pagination
$stmt_count = $conn->prepare($count_query);
if (!empty($sql_conditions)) {
    $types = str_repeat("s", count($sql_params) - 2); // Exclude LIMIT parameters
    if (!empty($types)) {
        $stmt_count->bind_param($types, ...array_slice($sql_params, 0, count($sql_params) - 2));
    }
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get payment method options
$payment_methods = [];
$payment_method_query = "SELECT DISTINCT payment_method FROM bookings WHERE payment_method IS NOT NULL";
$payment_method_result = $conn->query($payment_method_query);
if ($payment_method_result && $payment_method_result->num_rows > 0) {
    while ($row = $payment_method_result->fetch_assoc()) {
        if (!empty($row['payment_method'])) {
            $payment_methods[] = $row['payment_method'];
        }
    }
}

// Dashboard stats for payments
$total_payments = 0;
$verified_payments = 0;
$pending_payments = 0;
$rejected_payments = 0;

// Total payments
$query = "SELECT COUNT(*) as total FROM bookings WHERE payment_method IS NOT NULL";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $total_payments = $result->fetch_assoc()['total'];
}

// Verified payments
$query = "SELECT COUNT(*) as total FROM bookings WHERE payment_status = 'verified'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $verified_payments = $result->fetch_assoc()['total'];
}

// Pending payments
$query = "SELECT COUNT(*) as total FROM bookings WHERE payment_status = 'pending' OR payment_status = 'awaiting_verification'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $pending_payments = $result->fetch_assoc()['total'];
}

// Rejected payments
$query = "SELECT COUNT(*) as total FROM bookings WHERE payment_status = 'rejected'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $rejected_payments = $result->fetch_assoc()['total'];
}

// Calculate revenue from verified payments
$total_revenue = 0;
$query = "SELECT SUM(r.fare) as total_revenue 
          FROM bookings b 
          JOIN buses bus ON b.bus_id = bus.id 
          JOIN routes r ON bus.route_id = r.id 
          WHERE b.payment_status = 'verified'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $total_revenue = $row['total_revenue'] ? $row['total_revenue'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .payment-proof-img {
            max-width: 100px;
            max-height: 100px;
            cursor: pointer;
        }
        
        .modal-img {
            max-width: 100%;
        }
        
        .status-badge {
            text-transform: capitalize;
        }
        
        .stats-card {
            transition: all 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .stats-card .card-body {
            padding: 1.5rem;
        }
        
        .stats-card i {
            font-size: 2rem;
            opacity: 0.8;
        }
        
        .payment-filters {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .payment-filters .form-control, .payment-filters .form-select {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header d-flex align-items-center">
                <i class="fas fa-bus-alt me-2 fs-4"></i>
                <h4 class="mb-0">Admin Booking System</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../../admin.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="bookings_admin.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="routes_admin.php">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="schedules_admin.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="buses_admin.php">
                        <i class="fas fa-bus"></i>
                        <span>Buses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users_admin.php">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="payments_admin.php">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg navbar-light mb-4">
                <div class="container-fluid">
                    <button id="sidebarToggle" class="btn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <form class="d-flex ms-auto" method="get" action="payments_admin.php">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" 
                                    placeholder="Search by name or email" 
                                    aria-label="Search"
                                    value="<?php echo htmlspecialchars($search); ?>">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid px-4">
                <h2 class="mt-4 mb-4">Payment Management</h2>
                
                <!-- Flash Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Payment Stats -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card mb-4 bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-white-50">Total Payments</h6>
                                        <h3 class="mb-0"><?php echo number_format($total_payments); ?></h3>
                                    </div>
                                    <div>
                                        <i class="fas fa-money-check-alt"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card mb-4 bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-white-50">Verified Payments</h6>
                                        <h3 class="mb-0"><?php echo number_format($verified_payments); ?></h3>
                                    </div>
                                    <div>
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card mb-4 bg-warning text-dark">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-dark-50">Pending Verification</h6>
                                        <h3 class="mb-0"><?php echo number_format($pending_payments); ?></h3>
                                    </div>
                                    <div>
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stats-card mb-4 bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-white-50">Total Revenue</h6>
                                        <h3 class="mb-0">₱<?php echo number_format($total_revenue, 2); ?></h3>
                                    </div>
                                    <div>
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Filters -->
                <div class="payment-filters">
                    <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="row g-3">
                        <div class="col-md-2">
                            <label for="status" class="form-label">Payment Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php if ($status_filter === 'pending') echo 'selected'; ?>>Pending</option>
                                <option value="awaiting_verification" <?php if ($status_filter === 'awaiting_verification') echo 'selected'; ?>>Awaiting Verification</option>
                                <option value="verified" <?php if ($status_filter === 'verified') echo 'selected'; ?>>Verified</option>
                                <option value="rejected" <?php if ($status_filter === 'rejected') echo 'selected'; ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="">All Methods</option>
                                <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?php echo htmlspecialchars($method); ?>" <?php if ($payment_method_filter === $method) echo 'selected'; ?>>
                                        <?php echo ucfirst(htmlspecialchars($method)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Booking ID, Customer Name" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>
                
                <!-- Payments Table -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-money-check-alt me-1"></i>
                        Payment Records
                    </div>
                    <div class="card-body">
                        <?php if (count($payments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Booking Ref</th>
                                            <th>Customer</th>
                                            <th>Route</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Discount</th>
                                            <th>Proof</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                            <?php 
                                            // Apply discount if applicable
                                            $fare = $payment['fare'];
                                            if ($payment['discount_type'] == 'student' || $payment['discount_type'] == 'senior' || $payment['discount_type'] == 'pwd') {
                                                $fare = $fare * 0.8; // 20% discount
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($payment['booking_reference']); ?>
                                                    <div class="small text-muted">Trip: <?php echo htmlspecialchars($payment['trip_number']); ?></div>
                                                    <div class="small text-muted">Seat: <?php echo htmlspecialchars($payment['seat_number']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($payment['origin'] . ' to ' . $payment['destination']); ?></td>
                                                <td>
                                                    ₱<?php echo number_format($fare, 2); ?>
                                                    <?php if ($payment['discount_type'] != 'regular'): ?>
                                                        <div class="badge bg-info text-white"><?php echo ucfirst($payment['discount_type']); ?> Discount</div>
                                                        
                                                        <?php if (!empty($payment['discount_id_proof'])): ?>
                                                            <?php 
                                                            // Extract the ID number from the filename
                                                            $filename = basename($payment['discount_id_proof']);
                                                            $id_parts = explode('_', $filename);
                                                            $id_number = isset($id_parts[2]) ? $id_parts[2] : 'Unknown';
                                                            ?>
                                                            <div class="small text-muted mt-1">ID: <?php echo htmlspecialchars($id_number); ?></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo ucfirst(htmlspecialchars($payment['payment_method'] ?? 'N/A')); ?></td>
                                                <td><?php echo date('M d, Y g:i A', strtotime($payment['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($payment['payment_status'] == 'verified'): ?>
                                                        <span class="badge bg-success">Verified</span>
                                                    <?php elseif ($payment['payment_status'] == 'pending'): ?>
                                                        <span class="badge bg-warning text-dark">Pending</span>
                                                    <?php elseif ($payment['payment_status'] == 'awaiting_verification' || $payment['payment_status'] == 'awaiting_verificatio'): ?>
                                                        <span class="badge bg-primary">Awaiting Verification</span>
                                                    <?php elseif ($payment['payment_status'] == 'rejected'): ?>
                                                        <span class="badge bg-danger">Rejected</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><?php echo ucfirst($payment['payment_status']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($payment['discount_type'] != 'regular'): ?>
                                                        <div class="badge bg-info text-white mb-1"><?php echo ucfirst($payment['discount_type']); ?></div>
                                                        <?php if (!empty($payment['discount_id_proof'])): ?>
                                                            <?php 
                                                            $filename = basename($payment['discount_id_proof']);
                                                            $id_parts = explode('_', $filename);
                                                            $id_number = isset($id_parts[2]) ? $id_parts[2] : 'Unknown';
                                                            ?>
                                                            <div class="small mb-1">ID: <?php echo htmlspecialchars($id_number); ?></div>
                                                            <img src="../../<?php echo htmlspecialchars($payment['discount_id_proof']); ?>" 
                                                                class="payment-proof-img" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#discountIdModal" 
                                                                data-img-src="../../<?php echo htmlspecialchars($payment['discount_id_proof']); ?>"
                                                                data-discount-type="<?php echo ucfirst(htmlspecialchars($payment['discount_type'])); ?>"
                                                                data-booking-id="<?php echo $payment['id']; ?>"
                                                                alt="Discount ID Proof">
                                                        <?php else: ?>
                                                            <div class="small text-muted">No ID proof</div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Regular</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['payment_proof'])): ?>
                                                        <img src="../../<?php echo htmlspecialchars($payment['payment_proof']); ?>" 
                                                            class="payment-proof-img" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#proofModal" 
                                                            data-img-src="../../<?php echo htmlspecialchars($payment['payment_proof']); ?>"
                                                            data-booking-id="<?php echo $payment['id']; ?>"
                                                            data-discount-type="<?php echo htmlspecialchars($payment['discount_type']); ?>"
                                                            data-discount-id="<?php echo !empty($payment['discount_id_proof']) ? htmlspecialchars($payment['discount_id_proof']) : ''; ?>"
                                                            alt="Payment Proof">
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No Proof</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($payment['payment_status'] == 'awaiting_verification' || $payment['payment_status'] == 'pending' || $payment['payment_status'] == 'awaiting_verificatio'): ?>
                                                        <div class="d-flex flex-column gap-2">
                                                            <form method="post" class="mb-1">
                                                                <input type="hidden" name="booking_id" value="<?php echo $payment['id']; ?>">
                                                                <input type="hidden" name="action" value="approve_payment">
                                                                <button type="submit" class="btn btn-sm btn-success w-100" onclick="return confirm('Are you sure you want to verify this payment?')">
                                                                    <i class="fas fa-check"></i> Verify Payment
                                                                </button>
                                                            </form>
                                                            
                                                            <!--<form method="post" class="mb-1">
                                                                <input type="hidden" name="booking_id" value="<?php echo $payment['id']; ?>">
                                                                <input type="hidden" name="action" value="mark_counter_payment">
                                                                <button type="submit" class="btn btn-sm btn-primary w-100" onclick="return confirm('Mark as paid at counter?')">
                                                                    <i class="fas fa-cash-register"></i> Mark as Counter Payment
                                                                </button>
                                                            </form>-->
                                                            
                                                            <form method="post">
                                                                <input type="hidden" name="booking_id" value="<?php echo $payment['id']; ?>">
                                                                <input type="hidden" name="action" value="reject_payment">
                                                                <button type="submit" class="btn btn-sm btn-danger w-100" onclick="return confirm('Are you sure you want to reject this payment?')">
                                                                    <i class="fas fa-times"></i> Reject
                                                                </button>
                                                            </form>
                                                        </div>
                                                    <?php elseif ($payment['payment_status'] == 'verified'): ?>
                                                        <div class="text-success mb-2"><i class="fas fa-check-circle"></i> Verified</div>
                                                        <?php if ($payment['payment_method'] == 'counter'): ?>
                                                            <span class="badge bg-primary"><i class="fas fa-cash-register"></i> Counter Payment</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($payment['payment_status'] == 'rejected'): ?>
                                                        <div class="text-danger"><i class="fas fa-times-circle"></i> Rejected</div>
                                                        <form method="post" class="mt-1">
                                                            <input type="hidden" name="booking_id" value="<?php echo $payment['id']; ?>">
                                                            <input type="hidden" name="action" value="mark_counter_payment">
                                                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Mark as paid at counter?')">
                                                                <i class="fas fa-cash-register"></i> Mark as Counter Payment
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
                                            <a class="page-link" href="?page=<?php echo $page-1; ?>&status=<?php echo urlencode($status_filter); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                                        </li>
                                        
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php if ($page == $i) echo 'active'; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php if ($page >= $total_pages) echo 'disabled'; ?>">
                                            <a class="page-link" href="?page=<?php echo $page+1; ?>&status=<?php echo urlencode($status_filter); ?>&payment_method=<?php echo urlencode($payment_method_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>">Next</a>
                                        </li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">No payment records found.</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Export Payment Report Button -->
                <div class="text-end mb-4">
                    <button id="exportBtn" class="btn btn-success">
                        <i class="fas fa-file-export me-1"></i> Export Payment Report
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payment Proof Modal -->
    <div class="modal fade" id="proofModal" tabindex="-1" aria-labelledby="proofModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="proofModalLabel">Payment Proof</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="modal-img" alt="Payment Proof">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="downloadProof" href="#" class="btn btn-primary" download>Download</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Discount ID Modal -->
    <div class="modal fade" id="discountIdModal" tabindex="-1" aria-labelledby="discountIdModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="discountIdModalLabel">Discount ID Verification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <h6 id="discountTypeDisplay" class="mb-3"></h6>
                    <img id="discountIdImage" src="" class="modal-img" alt="Discount ID">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="downloadId" href="#" class="btn btn-primary" download>Download</a>
                    <form method="post" id="verifyDiscountForm">
                        <input type="hidden" name="booking_id" id="discountBookingId" value="">
                        <input type="hidden" name="action" value="verify_discount">
                        <!--<button type="submit" class="btn btn-success">Verify Discount</button>-->
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // Payment proof modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Handle payment proof modal
            const proofModal = document.getElementById('proofModal');
            if (proofModal) {
                proofModal.addEventListener('show.bs.modal', function(event) {
                    // Button that triggered the modal
                    const img = event.relatedTarget;
                    // Extract info from data-* attributes
                    const imgSrc = img.getAttribute('data-img-src');
                    
                    // Update the modal's content
                    const modalImage = document.getElementById('modalImage');
                    modalImage.src = imgSrc;
                    
                    // Update download link
                    const downloadLink = document.getElementById('downloadProof');
                    downloadLink.href = imgSrc;
                });
            }
            
            // Handle discount ID modal
            const discountIdModal = document.getElementById('discountIdModal');
            if (discountIdModal) {
                discountIdModal.addEventListener('show.bs.modal', function(event) {
                    // Button that triggered the modal
                    const button = event.relatedTarget;
                    // Extract info from data-* attributes
                    const imgSrc = button.getAttribute('data-img-src');
                    const discountType = button.getAttribute('data-discount-type');
                    
                    // Get booking ID - fixing the selector to find it correctly
                    let bookingId;
                    try {
                        // Try to get booking ID from the parent row first form
                        const parentRow = button.closest('tr');
                        if (parentRow) {
                            const bookingIdInput = parentRow.querySelector('form input[name="booking_id"]');
                            if (bookingIdInput) {
                                bookingId = bookingIdInput.value;
                            }
                        }
                    } catch (e) {
                        console.error('Error getting booking ID:', e);
                    }
                    
                    // Update the modal's content
                    const discountIdImage = document.getElementById('discountIdImage');
                    discountIdImage.src = imgSrc;
                    
                    // Update discount type display
                    const discountTypeDisplay = document.getElementById('discountTypeDisplay');
                    discountTypeDisplay.textContent = discountType + ' ID Verification';
                    
                    // Update download link
                    const downloadLink = document.getElementById('downloadId');
                    downloadLink.href = imgSrc;
                    
                    // Update form's booking ID if available
                    if (bookingId) {
                        const discountBookingId = document.getElementById('discountBookingId');
                        if (discountBookingId) {
                            discountBookingId.value = bookingId;
                        }
                    }
                });
            }
            
            // Handle export button
            const exportBtn = document.getElementById('exportBtn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    exportPaymentReport();
                });
            }
            
            // Add confirmation for dangerous actions
            document.querySelectorAll('.btn-danger').forEach(function(button) {
                button.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to reject this payment?')) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            });
        });

        // Function to export payment data to Excel
        function exportPaymentReport() {
            // Get table data
            const table = document.querySelector('.table');
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            if (rows.length === 0) {
                alert('No data to export');
                return;
            }
            
            // Prepare data for export
            const data = [
                ['Booking Reference', 'Customer', 'Route', 'Amount', 'Method', 'Date', 'Status']
            ];
            
            rows.forEach(row => {
                const cells = Array.from(row.querySelectorAll('td'));
                
                // Get booking reference (first line of first cell)
                const bookingRef = cells[0].innerText.split('\n')[0].trim();
                
                // Get customer name
                const customer = cells[1].innerText;
                
                // Get route
                const route = cells[2].innerText;
                
                // Get amount (first line of amount cell)
                const amount = cells[3].innerText.split('\n')[0].trim();
                
                // Get payment method
                const method = cells[4].innerText;
                
                // Get date
                const date = cells[5].innerText;
                
                // Get status
                const statusElement = cells[6].querySelector('.badge');
                const status = statusElement ? statusElement.innerText : '';
                
                data.push([bookingRef, customer, route, amount, method, date, status]);
            });
            
            // Create workbook and add data
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(data);
            
            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, 'Payment Report');
            
            // Generate filename with current date
            const now = new Date();
            const dateStr = now.toISOString().split('T')[0];
            const filename = `Payment_Report_${dateStr}.xlsx`;
            
            // Export workbook
            XLSX.writeFile(wb, filename);
        }

        // Print payment receipt
        function printReceipt(bookingId) {
            window.open(`print_receipt.php?id=${bookingId}`, '_blank', 'width=800,height=600');
        }

        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>