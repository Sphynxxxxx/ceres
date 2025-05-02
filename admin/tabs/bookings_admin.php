<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

// Handle form actions
$success_message = '';
$error_message = '';

// Handle booking status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking_status'])) {
    $booking_id = $_POST['booking_id'];
    $new_status = $_POST['booking_status'];
    
    try {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $booking_id);
        $stmt->execute();
        
        $success_message = "Booking status updated successfully!";
    } catch (Exception $e) {
        $error_message = "Error updating booking status: " . $e->getMessage();
    }
}

// Handle booking deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking'])) {
    $booking_id = $_POST['booking_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        
        $success_message = "Booking deleted successfully!";
    } catch (Exception $e) {
        $error_message = "Error deleting booking: " . $e->getMessage();
    }
}

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch bookings with related information
$bookings = [];
try {
    // Apply filters if set
    $whereConditions = [];
    $whereParams = [];
    $paramTypes = "";
    
    // Search by name or email
    if (!empty($search_query)) {
        $whereConditions[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ?)";
        $searchParam = "%" . $search_query . "%";
        $whereParams[] = $searchParam;
        $whereParams[] = $searchParam;
        $paramTypes .= "ss";
    }
    
    // Existing status filter
    if (isset($_GET['filter_status']) && !empty($_GET['filter_status'])) {
        $whereConditions[] = "b.booking_status = ?";
        $whereParams[] = $_GET['filter_status'];
        $paramTypes .= "s";
    }
    
    // ... (rest of your existing date filters remain the same)

    // Base query remains the same, with added search conditions
    $baseQuery = "SELECT 
                    b.id as booking_id, 
                    b.booking_date, 
                    b.booking_status, 
                    b.seat_number,
                    CONCAT(u.first_name, ' ', u.last_name) as user_name,
                    u.email as user_email,
                    s.origin,
                    s.destination,
                    s.departure_time,
                    bus.plate_number,
                    bus.bus_type,
                    CASE 
                        WHEN b.booking_date < CURRENT_DATE AND b.booking_status != 'cancelled' THEN 'expired'
                        ELSE b.booking_status 
                    END as display_status
                  FROM bookings b
                  JOIN users u ON b.user_id = u.id
                  JOIN schedules s ON s.bus_id = b.bus_id
                  JOIN buses bus ON bus.id = b.bus_id";
    
    // Add WHERE clause if filters were applied
    if (!empty($whereConditions)) {
        $baseQuery .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    // Add ORDER BY clause
    $baseQuery .= " ORDER BY b.booking_date DESC";
    
    // Prepare and execute the query
    if (!empty($whereParams)) {
        $stmt = $conn->prepare($baseQuery);
        $stmt->bind_param($paramTypes, ...$whereParams);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($baseQuery);
    }
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error fetching bookings: " . $e->getMessage();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings Management - ISAT-U Ceres Bus Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/admin.css" rel="stylesheet">
    
    <style>
        .booking-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-confirmed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-expired {
            background-color: #6c757d;
            color: #ffffff;
        }
        .booking-card {
            transition: transform 0.3s;
        }
        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
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
                    <a class="nav-link active" href="bookings_admin.php">
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
                    <a class="nav-link" href="payments_admin.php">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="announcements_admin.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Page Content -->
        <div class="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg navbar-light mb-4">
                <div class="container-fluid">
                    <button id="sidebarToggle" class="btn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <form class="d-flex ms-auto" method="get" action="bookings_admin.php">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" 
                                    placeholder="Search by name or email" 
                                    aria-label="Search"
                                    value="<?php echo htmlspecialchars($search_query); ?>">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-ticket-alt me-2"></i>Bookings Management</h2>
                </div>

                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="get" action="bookings_admin.php">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="filter_status" class="form-select">
                                        <option value="">All Statuses</option>
                                        <option value="pending" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="cancelled" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?php echo isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : ''; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" name="end_date" class="form-control" value="<?php echo isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : ''; ?>">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-2"></i>Apply Filters
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bookings Overview -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5><i class="fas fa-ticket-alt mb-3"></i></h5>
                                <h3 class="mb-0"><?php echo count($bookings); ?></h3>
                                <p class="text-muted">Total Bookings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5><i class="fas fa-check-circle mb-3 text-success"></i></h5>
                                <h3 class="mb-0">
                                    <?php 
                                    $confirmed = array_filter($bookings, function($booking) {
                                        return $booking['booking_status'] === 'confirmed';
                                    });
                                    echo count($confirmed); 
                                    ?>
                                </h3>
                                <p class="text-muted">Confirmed Bookings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5><i class="fas fa-times-circle mb-3 text-danger"></i></h5>\
                                <h3 class="mb-0">
                                    <?php 
                                    $cancelled = array_filter($bookings, function($booking) {
                                        return $booking['booking_status'] === 'cancelled';
                                    });
                                    echo count($cancelled); 
                                    ?>
                                </h3>
                                <p class="text-muted">Cancelled Bookings</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($search_query)): ?>
                <div class="alert alert-info" role="alert">
                    <i class="fas fa-search me-2"></i>
                    Search results for: <strong><?php echo htmlspecialchars($search_query); ?></strong>
                    <?php if (count($bookings) === 0): ?>
                        <br><small>No bookings found matching your search.</small>
                    <?php else: ?>
                        <br><small><?php echo count($bookings); ?> booking(s) found</small>
                    <?php endif; ?>
                    <a href="bookings_admin.php" class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="fas fa-times me-1"></i>Clear Search
                    </a>
                </div>
                <?php endif; ?>

                <!-- Bookings Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Booking Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($bookings) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Passenger</th>
                                        <th>Route</th>
                                        <th>Bus Details</th>
                                        <th>Booking Date</th>
                                        <th>Seat Number</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>#<?php echo htmlspecialchars($booking['booking_id']); ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($booking['user_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($booking['user_email']); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-bold">
                                                <?php echo htmlspecialchars($booking['origin']); ?> → 
                                                <?php echo htmlspecialchars($booking['destination']); ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('h:i A', strtotime($booking['departure_time'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($booking['plate_number']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($booking['bus_type']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo date('Y-m-d H:i', strtotime($booking['booking_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo htmlspecialchars($booking['seat_number']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = '';
                                            switch ($booking['display_status']) {
                                                case 'pending':
                                                    $status_class = 'status-pending';
                                                    break;
                                                case 'confirmed':
                                                    $status_class = 'status-confirmed';
                                                    break;
                                                case 'cancelled':
                                                    $status_class = 'status-cancelled';
                                                    break;
                                                case 'expired':
                                                    $status_class = 'status-expired';
                                                    break;
                                            }
                                            ?>
                                            <span class="booking-status <?php echo $status_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($booking['display_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#updateBookingModal"
                                                        data-booking-id="<?php echo $booking['booking_id']; ?>"
                                                        data-current-status="<?php echo $booking['booking_status']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteBookingModal"
                                                        data-booking-id="<?php echo $booking['booking_id']; ?>"
                                                        data-passenger-name="<?php echo htmlspecialchars($booking['user_name']); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle me-2"></i>No bookings found.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Booking Status Modal -->
    <div class="modal fade" id="updateBookingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Booking Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="bookings_admin.php">
                        <input type="hidden" name="booking_id" id="update_booking_id">
                        <input type="hidden" name="update_booking_status" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Change Booking Status</label>
                            <select name="booking_status" id="update_booking_status" class="form-select" required>
                                <option value="confirmed">Confirmed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Status
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Booking Confirmation Modal -->
    <div class="modal fade" id="deleteBookingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Booking Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="bookings_admin.php">
                        <input type="hidden" name="booking_id" id="delete_booking_id">
                        <input type="hidden" name="delete_booking" value="1">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Are you sure you want to delete the booking for 
                            <span id="delete_passenger_name" class="fw-bold"></span>?
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-2"></i>Delete Booking
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.body.classList.toggle('collapsed-sidebar');
        });

        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        // Update Booking Status Modal Handling
        const updateBookingModal = document.getElementById('updateBookingModal');
        updateBookingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const bookingId = button.getAttribute('data-booking-id');
            const currentStatus = button.getAttribute('data-current-status');
            
            document.getElementById('update_booking_id').value = bookingId;
            document.getElementById('update_booking_status').value = currentStatus;
        });

        // Delete Booking Modal Handling
        const deleteBookingModal = document.getElementById('deleteBookingModal');
        deleteBookingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const bookingId = button.getAttribute('data-booking-id');
            const passengerName = button.getAttribute('data-passenger-name');
            
            document.getElementById('delete_booking_id').value = bookingId;
            document.getElementById('delete_passenger_name').textContent = passengerName;
        });

        // Add sidebar toggle functionality
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.querySelector('.content').classList.toggle('expanded');
        });
    </script>
</body>
</html>