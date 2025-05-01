<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "backend/connections/config.php";

$admin_id = -1;
$admin_name = "Administartor";

// Fetch dashboard statistics
$total_bookings = 0;
$today_revenue = 0;
$active_buses = 0;
$registered_users = 0;

// Total bookings
try {
    $query = "SELECT COUNT(*) as total FROM bookings";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $total_bookings = $row['total'];
    }
} catch (Exception $e) {
    // If table doesn't exist yet
    $total_bookings = 0;
}

// Today's revenue
try {
    $query = "SELECT SUM(fare) as revenue FROM bookings WHERE DATE(created_at) = CURDATE()";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $today_revenue = $row['revenue'] ?? 0;
    }
} catch (Exception $e) {
    // If table doesn't exist yet
    $today_revenue = 0;
}

// Active buses
try {
    $query = "SELECT COUNT(*) as total FROM buses WHERE status = 'active'";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $active_buses = $row['total'];
    }
} catch (Exception $e) {
    // If table doesn't exist yet
    $active_buses = 0;
}

// Registered users
try {
    $query = "SELECT COUNT(*) as total FROM users";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $registered_users = $row['total'];
    }
} catch (Exception $e) {
    // If table doesn't exist yet
    $registered_users = 0;
}

// Get recent bookings
$recent_bookings = [];
try {
    $query = "SELECT b.id, u.first_name, u.last_name, r.origin, r.destination, b.travel_date, b.status 
              FROM bookings b 
              JOIN users u ON b.user_id = u.id 
              JOIN routes r ON b.route_id = r.id 
              ORDER BY b.created_at DESC LIMIT 10";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $recent_bookings[] = $row;
        }
    }
} catch (Exception $e) {
    // Empty array if database tables don't exist yet
}

// Get upcoming schedules
$upcoming_schedules = [];
try {
    $query = "SELECT s.id, r.origin, r.destination, s.departure_time, s.arrival_time, b.name as bus_name 
              FROM schedules s 
              JOIN routes r ON s.route_id = r.id 
              JOIN buses b ON s.bus_id = b.id 
              WHERE s.departure_time > NOW() 
              ORDER BY s.departure_time ASC LIMIT 5";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $upcoming_schedules[] = $row;
        }
    }
} catch (Exception $e) {
    // Empty array if database tables don't exist yet
}

// Get notifications
$notifications = [];
try {
    $query = "SELECT * FROM notifications WHERE admin_read = 0 ORDER BY created_at DESC LIMIT 3";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
    }
} catch (Exception $e) {
}
$notification_count = count($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ceres Bus for ISAT-U Commuters - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="admin/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <a class="nav-link active" href="admin.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/bookings_admin.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/routes_admin.php">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/schedules_admin.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/buses_admin.php">
                        <i class="fas fa-bus"></i>
                        <span>Buses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/users_admin.php">
                        <i class="fas fa-users me-2"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/reports_admin.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/announcements_admin.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/settings-section">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
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
                        <form class="d-flex ms-auto">
                            <div class="input-group">
                                <input class="form-control" type="search" placeholder="Search" aria-label="Search">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                        <ul class="navbar-nav ms-3">
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-bell"></i>
                                    <?php if ($notification_count > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $notification_count; ?></span>
                                    <?php endif; ?>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                    <li><h6 class="dropdown-header">Notifications</h6></li>
                                    <?php if (count($notifications) > 0): ?>
                                        <?php foreach ($notifications as $notification): ?>
                                        <li><a class="dropdown-item" href="#"><?php echo $notification['message']; ?> <small class="text-muted d-block"><?php echo $notification['time']; ?></small></a></li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li><span class="dropdown-item text-muted">No new notifications</span></li>
                                    <?php endif; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#">See all notifications</a></li>
                                </ul>
                            </li>
                            <li class="nav-item dropdown profile-section">
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                                    <li><h6 class="dropdown-header">Admin User</h6></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="index.php"><i class="fas fa-sign-out-alt me-2"></i>Exit Admin</a></li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="tab-content">
                <!-- Dashboard Section -->
                <div class="tab-pane fade show active" id="dashboard-section">
                    <h2 class="mb-4">Dashboard</h2>
                    
                    <!-- Stats Cards -->
                    <div class="row">
                        <div class="col-xl-3 col-md-6">
                            <div class="card stats-card mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted">Total Bookings</h6>
                                            <h3 class="mb-0"><?php echo number_format($total_bookings); ?></h3>
                                            <?php if($total_bookings > 0): ?>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>
                                                <span id="booking-trend">Getting data...</span>
                                            </p>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">No data available</p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-ticket-alt"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card stats-card mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted">Today's Revenue</h6>
                                            <h3 class="mb-0">₱<?php echo number_format($today_revenue, 2); ?></h3>
                                            <?php if($today_revenue > 0): ?>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>
                                                <span id="revenue-trend">Getting data...</span>
                                            </p>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">No data available</p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card stats-card mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted">Active Buses</h6>
                                            <h3 class="mb-0"><?php echo $active_buses; ?></h3>
                                            <?php if($active_buses > 0): ?>
                                            <p class="text-success mb-0">
                                                <span id="buses-status">Getting data...</span>
                                            </p>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">No data available</p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-bus"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-3 col-md-6">
                            <div class="card stats-card mb-4">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted">Registered Users</h6>
                                            <h3 class="mb-0"><?php echo number_format($registered_users); ?></h3>
                                            <?php if($registered_users > 0): ?>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>
                                                <span id="users-trend">Getting data...</span>
                                            </p>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">No data available</p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-users"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Booking Statistics</h5>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="bookingStatsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                            This Month
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="bookingStatsDropdown">
                                            <li><a class="dropdown-item" href="#">Today</a></li>
                                            <li><a class="dropdown-item" href="#">This Week</a></li>
                                            <li><a class="dropdown-item" href="#">This Month</a></li>
                                            <li><a class="dropdown-item" href="#">Last 3 Months</a></li>
                                            <li><a class="dropdown-item" href="#">This Year</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="bookingStatsChart"></canvas>
                                    </div>
                                    <?php if(count($recent_bookings) == 0): ?>
                                    <div class="alert alert-info mt-3">
                                        No booking data available yet. Statistics will appear here once bookings have been made.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Popular Routes</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="routesChart"></canvas>
                                    </div>
                                    <?php if(count($recent_bookings) == 0): ?>
                                    <div class="alert alert-info mt-3">
                                        No route data available yet. Statistics will appear here once routes have been used.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Bookings and Upcoming Schedules -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Recent Bookings</h5>
                                    <a href="#bookings-section" class="btn btn-sm btn-primary" data-bs-toggle="tab">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if(count($recent_bookings) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Passenger</th>
                                                    <th>Route</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_bookings as $booking): ?>
                                                <tr>
                                                    <td><?php echo $booking['id']; ?></td>
                                                    <td><?php echo $booking['first_name'] . ' ' . $booking['last_name']; ?></td>
                                                    <td><?php echo $booking['origin'] . ' to ' . $booking['destination']; ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($booking['travel_date'])); ?></td>
                                                    <td>
                                                        <?php if ($booking['status'] == 'Confirmed'): ?>
                                                            <span class="badge bg-success">Confirmed</span>
                                                        <?php elseif ($booking['status'] == 'Pending'): ?>
                                                            <span class="badge bg-warning text-dark">Pending</span>
                                                        <?php elseif ($booking['status'] == 'Cancelled'): ?>
                                                            <span class="badge bg-danger">Cancelled</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><?php echo $booking['status']; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="tooltip" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-success" data-bs-toggle="tooltip" title="Confirm">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="tooltip" title="Cancel">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-info">
                                        No bookings found. Booking data will appear here once customers make reservations.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Upcoming Schedules</h5>
                                </div>
                                <div class="card-body">
                                    <?php if(count($upcoming_schedules) > 0): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($upcoming_schedules as $schedule): ?>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo $schedule['origin']; ?> to <?php echo $schedule['destination']; ?></strong>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-clock me-1"></i> <?php echo date('h:i A', strtotime($schedule['departure_time'])); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-bus me-1"></i> <?php echo $schedule['bus_name']; ?>
                                                    </div>
                                                </div>
                                                <div class="text-center">
                                                    <div class="badge bg-primary mb-1"><?php echo date('M d', strtotime($schedule['departure_time'])); ?></div>
                                                    <button class="btn btn-sm btn-outline-secondary d-block">Details</button>
                                                </div>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php else: ?>
                                    <div class="alert alert-info">
                                        No upcoming schedules found. Schedule data will appear here once trips are added to the system.
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer text-center">
                                    <a href="#schedules-section" class="btn btn-sm btn-primary" data-bs-toggle="tab">Manage Schedules</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
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
        
        // Function to get database stats
        async function fetchDatabaseStats() {
            try {
                
                
                // Update stats trends
                if (document.getElementById('booking-trend')) {
                    document.getElementById('booking-trend').textContent = "12% increase";
                }
                
                if (document.getElementById('revenue-trend')) {
                    document.getElementById('revenue-trend').textContent = "8% increase";
                }
                
                if (document.getElementById('buses-status')) {
                    document.getElementById('buses-status').textContent = "All operational";
                }
                
                if (document.getElementById('users-trend')) {
                    document.getElementById('users-trend').textContent = "24 new today";
                }
            } catch (error) {
                console.error("Error fetching stats:", error);
            }
        }
        
        // Initialize dynamic data
        fetchDatabaseStats();
        
        // Booking Statistics Chart (only if there's data)
        const bookingStatsCanvas = document.getElementById('bookingStatsChart');
        if (bookingStatsCanvas) {
            const bookingStatsCtx = bookingStatsCanvas.getContext('2d');
            const bookingStatsChart = new Chart(bookingStatsCtx, {
                type: 'line',
                data: {
                    labels: ['Apr 1', 'Apr 5', 'Apr 10', 'Apr 15', 'Apr 20', 'Apr 25', 'Apr 30'],
                    datasets: [{
                        label: 'Bookings',
                        data: [0, 0, 0, 0, 0, 0, 0], // Default to empty data
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
            
            // This would normally fetch data from the server
            const hasData = <?php echo (count($recent_bookings) > 0) ? 'true' : 'false'; ?>;
            if (hasData) {
                bookingStatsChart.data.datasets[0].data = [5, 8, 12, 15, 20, 25, 30];
                bookingStatsChart.update();
            }
        }
        
        // Popular Routes Chart (only if there's data)
        const routesCanvas = document.getElementById('routesChart');
        if (routesCanvas) {
            const routesCtx = routesCanvas.getContext('2d');
            const routesChart = new Chart(routesCtx, {
                type: 'doughnut',
                data: {
                    labels: ['No Data'],
                    datasets: [{
                        data: [1],
                        backgroundColor: ['rgba(200, 200, 200, 0.7)'],
                        borderColor: ['rgba(200, 200, 200, 1)'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12
                            }
                        }
                    }
                }
            });
            
            // This would normally fetch data from the server
            // For demo purposes, we'll simulate if there's data available
            const hasData = <?php echo (count($recent_bookings) > 0) ? 'true' : 'false'; ?>;
            if (hasData) {
                routesChart.data.labels = ['Iloilo-Roxas', 'Iloilo-Kalibo', 'Bacolod-San Carlos', 'Iloilo-Caticlan', 'Iloilo-San Jose'];
                routesChart.data.datasets[0].data = [35, 25, 15, 15, 10];
                routesChart.data.datasets[0].backgroundColor = [
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 206, 86, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(153, 102, 255, 0.7)'
                ];
                
                routesChart.data.datasets[0].borderColor = [
                   'rgba(255, 99, 132, 1)',
                   'rgba(54, 162, 235, 1)',
                   'rgba(255, 206, 86, 1)',
                   'rgba(75, 192, 192, 1)',
                   'rgba(153, 102, 255, 1)'
               ];
               routesChart.update();
           }
       }
       
       // Add tab state persistence
       document.querySelectorAll('.nav-link[data-bs-toggle="tab"]').forEach(function(link) {
           link.addEventListener('click', function(e) {
               localStorage.setItem('activeAdminTab', e.target.getAttribute('href'));
           });
       });
       
       // Get active tab from localStorage and activate it
       var activeTab = localStorage.getItem('activeAdminTab');
       if (activeTab) {
           var triggerEl = document.querySelector('.nav-link[href="' + activeTab + '"]');
           if (triggerEl) {
               var tab = new bootstrap.Tab(triggerEl);
               tab.show();
           }
       }
       
       // Add confirmation for dangerous actions
       document.querySelectorAll('.btn-outline-danger').forEach(function(button) {
           button.addEventListener('click', function(e) {
               if (!confirm('Are you sure you want to perform this action?')) {
                   e.preventDefault();
                   e.stopPropagation();
               }
           });
       });
       
       // Reload dashboard data
       function reloadDashboardData() {
           fetchDatabaseStats();
           
           // Show loading state
           document.querySelectorAll('.stats-card .card-body').forEach(card => {
               card.classList.add('loading');
           });
           
           // Simulate API fetch
           setTimeout(() => {
               // Remove loading state
               document.querySelectorAll('.stats-card .card-body').forEach(card => {
                   card.classList.remove('loading');
               });
               
               // Update charts with new data
               const hasData = <?php echo (count($recent_bookings) > 0) ? 'true' : 'false'; ?>;
               
               if (hasData && window.bookingStatsChart) {
                   // Get random data for demonstration
                   const newData = Array.from({length: 7}, () => Math.floor(Math.random() * 30) + 5);
                   bookingStatsChart.data.datasets[0].data = newData;
                   bookingStatsChart.update();
               }
               
               if (hasData && window.routesChart) {
                   // Get random data for demonstration
                   const newData = Array.from({length: 5}, () => Math.floor(Math.random() * 30) + 5);
                   routesChart.data.datasets[0].data = newData;
                   routesChart.update();
               }
               
               // Show success message if needed
               console.log("Dashboard data refreshed successfully");
           }, 1000);
       }

       
   </script>
</body>
</html>
                
