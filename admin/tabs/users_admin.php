<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../backend/connections/config.php";

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    if ($user_id > 0) {
        // Start a transaction
        $conn->begin_transaction();
        
        try {
            // First, delete or update any related records in contact_messages
            $delete_messages_query = "DELETE FROM contact_messages WHERE user_id = ?";
            $stmt = $conn->prepare($delete_messages_query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            // Now, delete the user
            $delete_query = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            // If everything went well, commit the transaction
            $conn->commit();
            
            $_SESSION['message'] = "User successfully deleted.";
            $_SESSION['message_type'] = "success";
        } catch (Exception $e) {
            // If there was an error, roll back the transaction
            $conn->rollback();
            
            $_SESSION['message'] = "Error deleting user: " . $e->getMessage();
            $_SESSION['message_type'] = "danger";
        }
        
        // Redirect to prevent form resubmission
        header("Location: users_admin.php");
        exit();
    }
}

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? $_GET['page'] : 1;
$start_from = ($page - 1) * $records_per_page;

// Search functionality
$search = isset($_GET['search']) ? $_GET['search'] : '';
$search_condition = '';
if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $search_condition = " WHERE first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR email LIKE '%$search%' OR contact_number LIKE '%$search%'";
}

// Get total number of users
$total_query = "SELECT COUNT(*) as total FROM users" . $search_condition;
$total_result = $conn->query($total_query);
$total_records = 0;
if ($total_result && $total_result->num_rows > 0) {
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
}
$total_pages = ceil($total_records / $records_per_page);

// Get users with pagination
$users = [];
try {
    $query = "SELECT id, first_name, last_name, gender, birthdate, contact_number, email, created_at 
              FROM users" . $search_condition . " 
              ORDER BY created_at DESC 
              LIMIT $start_from, $records_per_page";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle exception
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #1d3557;
        }
        
        .card1 {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .verified-badge {
            font-size: 0.75rem;
        }
        
        .filter-row {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        #userBookingHistory .table {
            font-size: 0.9rem;
        }
        
        #userBookingHistory .badge {
            font-size: 0.75rem;
        }
        
        .modal-xl {
            max-width: 1200px;
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
                    <a class="nav-link active" href="users_admin.php">
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
                    <a class="nav-link" href="inquiries.php">
                        <i class="fas fa-envelope"></i>
                        <span>Inquiries</span>
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
                        <form class="d-flex ms-auto" action="users_admin.php" method="GET">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" placeholder="Search users" value="<?php echo htmlspecialchars($search); ?>" aria-label="Search">
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
                    <h2><i class="fas fa-users me-2"></i>User Management</h2>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                    <?php 
                    echo htmlspecialchars($_SESSION['message']); 
                    unset($_SESSION['message']);
                    unset($_SESSION['message_type']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Users Table -->
                <div class="card1">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Registered Users</h5>
                            <span class="badge bg-primary"><?php echo $total_records; ?> users found</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($users) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Gender</th>
                                        <th>Contact</th>
                                        <th>Email</th>
                                        <th>Birthdate</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>#<?php echo $user['id']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-2">
                                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo ucfirst(htmlspecialchars($user['gender'])); ?></td>
                                        <td><?php echo htmlspecialchars($user['contact_number']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($user['birthdate'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-outline-primary btn-sm view-user" data-id="<?php echo $user['id']; ?>" data-bs-toggle="tooltip" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm delete-user" data-id="<?php echo $user['id']; ?>" data-bs-toggle="tooltip" title="Delete User">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mt-4">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="First">
                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Last">
                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No users found. 
                            <?php if (!empty($search)): ?>
                            Try a different search term or <a href="users_admin.php" class="alert-link">clear the search</a>.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addUserForm" method="post" action="process_user.php">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="firstName" class="form-label">First Name*</label>
                                <input type="text" class="form-control" id="firstName" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="lastName" class="form-label">Last Name*</label>
                                <input type="text" class="form-control" id="lastName" name="last_name" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender*</label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="" selected disabled>Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="birthdate" class="form-label">Birthdate*</label>
                                <input type="date" class="form-control" id="birthdate" name="birthdate" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="contactNumber" class="form-label">Contact Number*</label>
                                <input type="tel" class="form-control" id="contactNumber" name="contact_number" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address*</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="password" class="form-label">Password*</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="verificationStatus" class="form-label">Verification Status</label>
                                <select class="form-select" id="verificationStatus" name="is_verified">
                                    <option value="1">Verified</option>
                                    <option value="0" selected>Not Verified</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addUserForm" class="btn btn-primary">Add User</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user me-2"></i>User Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4" id="userProfileSection">
                        <!-- User profile details will be loaded here -->
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Personal Information</h6>
                                </div>
                                <div class="card-body" id="userPersonalInfo">
                                    <!-- Personal info will be loaded here -->
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-ticket-alt me-2"></i>Booking History</h6>
                                </div>
                                <div class="card-body" id="userBookingHistory" style="max-height: 500px; overflow-y: auto;">
                                    <!-- Booking history will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

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

        // Delete user functionality
        document.querySelectorAll('.delete-user').forEach(function(button) {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-id');
                if (confirm('Are you sure you want to delete user #' + userId + '? This action cannot be undone.')) {
                    // Create a form to submit delete request
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'users_admin.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_user';
                    form.appendChild(actionInput);
                    
                    const userIdInput = document.createElement('input');
                    userIdInput.type = 'hidden';
                    userIdInput.name = 'user_id';
                    userIdInput.value = userId;
                    form.appendChild(userIdInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });

        // View user details functionality
        document.querySelectorAll('.view-user').forEach(function(button) {
            button.addEventListener('click', function() {
                const userId = this.getAttribute('data-id');
                
                // Show loading state
                document.getElementById('userProfileSection').innerHTML = `
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `;
                document.getElementById('userPersonalInfo').innerHTML = `
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                `;
                document.getElementById('userBookingHistory').innerHTML = `
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                `;
                
                // Fetch user details via AJAX
                fetch(`../../backend/connections/get_user_details.php?user_id=${userId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Populate user profile section
                        if (data.success) {
                            const user = data.user;
                            const initials = (user.first_name.charAt(0) + user.last_name.charAt(0)).toUpperCase();
                            
                            document.getElementById('userProfileSection').innerHTML = `
                                <div class="d-flex justify-content-center mb-3">
                                    <div class="user-avatar" style="width: 80px; height: 80px; font-size: 2rem;">
                                        ${initials}
                                    </div>
                                </div>
                                <h4>${user.first_name} ${user.last_name}</h4>
                                <p class="text-muted">User ID: #${user.id}</p>
                            `;
                            
                            document.getElementById('userPersonalInfo').innerHTML = `
                                <dl class="row mb-0">
                                    <dt class="col-sm-5">Full Name:</dt>
                                    <dd class="col-sm-7">${user.first_name} ${user.last_name}</dd>
                                    
                                    <dt class="col-sm-5">Email:</dt>
                                    <dd class="col-sm-7">${user.email}</dd>
                                    
                                    <dt class="col-sm-5">Phone:</dt>
                                    <dd class="col-sm-7">${user.contact_number}</dd>
                                    
                                    <dt class="col-sm-5">Gender:</dt>
                                    <dd class="col-sm-7">${user.gender.charAt(0).toUpperCase() + user.gender.slice(1)}</dd>
                                    
                                    <dt class="col-sm-5">Birthdate:</dt>
                                    <dd class="col-sm-7">${new Date(user.birthdate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</dd>
                                    
                                    <dt class="col-sm-5">Joined:</dt>
                                    <dd class="col-sm-7">${new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</dd>
                                </dl>
                            `;
                            
                            // Populate booking history
                            if (data.bookings && data.bookings.length > 0) {
                                let bookingHistoryHTML = `
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Ref #</th>
                                                    <th>Date</th>
                                                    <th>Route</th>
                                                    <th>Status</th>
                                                    <th>Payment</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                `;
                                
                                data.bookings.forEach(booking => {
                                    let statusBadge = '';
                                    switch(booking.booking_status) {
                                        case 'confirmed':
                                            statusBadge = '<span class="badge bg-success">Confirmed</span>';
                                            break;
                                        case 'pending':
                                            statusBadge = '<span class="badge bg-warning">Pending</span>';
                                            break;
                                        case 'cancelled':
                                            statusBadge = '<span class="badge bg-danger">Cancelled</span>';
                                            break;
                                        default:
                                            statusBadge = `<span class="badge bg-secondary">${booking.booking_status}</span>`;
                                    }
                                    
                                    let paymentBadge = '';
                                    switch(booking.payment_status) {
                                        case 'verified':
                                            paymentBadge = '<span class="badge bg-success">Verified</span>';
                                            break;
                                        case 'pending':
                                            paymentBadge = '<span class="badge bg-warning">Pending</span>';
                                            break;
                                        case 'rejected':
                                            paymentBadge = '<span class="badge bg-danger">Rejected</span>';
                                            break;
                                        default:
                                            paymentBadge = `<span class="badge bg-secondary">${booking.payment_status}</span>`;
                                    }
                                    
                                    bookingHistoryHTML += `
                                        <tr>
                                            <td>${booking.booking_reference}</td>
                                            <td>${new Date(booking.booking_date).toLocaleDateString()}</td>
                                            <td>${booking.origin} → ${booking.destination}</td>
                                            <td>${statusBadge}</td>
                                            <td>${paymentBadge}</td>
                                            <td>₱${parseFloat(booking.fare_amount).toFixed(2)}</td>
                                        </tr>
                                    `;
                                });
                                
                                bookingHistoryHTML += `
                                            </tbody>
                                        </table>
                                    </div>
                                `;
                                
                                document.getElementById('userBookingHistory').innerHTML = bookingHistoryHTML;
                            } else {
                                document.getElementById('userBookingHistory').innerHTML = `
                                    <p class="text-center text-muted">No booking history available.</p>
                                `;
                            }
                            
                            // Show the modal
                            var viewUserModal = new bootstrap.Modal(document.getElementById('viewUserModal'));
                            viewUserModal.show();
                        } else {
                            alert('Failed to load user details: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to load user details.');
                    });
            });
        });
    </script>
</body>
</html>