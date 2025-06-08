<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "backend/connections/config.php";

$admin_id = -1;
$admin_name = "Administrator";

// Fetch dashboard statistics
$total_bookings = 0;
$total_revenue = 0;
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
    $total_bookings = 0;
}

// Total revenue
$total_revenue = 0;
$today_revenue = 0;
$today = date('Y-m-d');

try {
    // Calculate total revenue (all verified payments)
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
    
    // Calculate today's revenue (verified payments for today)
    $query = "SELECT SUM(r.fare) as today_revenue 
              FROM bookings b 
              JOIN buses bus ON b.bus_id = bus.id 
              JOIN routes r ON bus.route_id = r.id 
              WHERE b.payment_status = 'verified' 
              AND DATE(b.created_at) = '$today'";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $today_revenue = $row['today_revenue'] ? $row['today_revenue'] : 0;
    }
} catch (Exception $e) {
    $total_revenue = 0;
    $today_revenue = 0;
}

// Active buses 
try {
    $query = "SELECT COUNT(*) as total FROM buses WHERE status = 'Active'";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $active_buses = $row['total'];
    }
} catch (Exception $e) {
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
    $registered_users = 0;
}

// Get recent bookings - Fixed query to use buses table for route info
$recent_bookings = [];
try {
    $query = "SELECT b.id, u.first_name, u.last_name, 
              bs.origin, bs.destination, b.booking_date as travel_date, b.booking_status as status 
              FROM bookings b 
              JOIN users u ON b.user_id = u.id 
              JOIN buses bs ON b.bus_id = bs.id 
              ORDER BY b.created_at DESC LIMIT 10";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $recent_bookings[] = $row;
        }
    }
} catch (Exception $e) {
    // Handle error
}

// Get upcoming schedules - Fixed for recurring schedules
$upcoming_schedules = [];
try {
    $query = "SELECT s.id, s.origin, s.destination, s.departure_time, s.arrival_time, 
              b.bus_type as bus_name, s.trip_number
              FROM schedules s 
              JOIN buses b ON s.bus_id = b.id 
              WHERE s.status = 'active'
              ORDER BY s.departure_time ASC LIMIT 5";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $upcoming_schedules[] = $row;
        }
    }
} catch (Exception $e) {
}

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
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/payments_admin.php">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin/tabs/inquiries.php">
                        <i class="fas fa-envelope"></i>
                        <span>Inquiries</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-danger" href="admin/tabs/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
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
                </div>
            </nav>

            <!-- Main Content -->
            <div class="tab-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h2>
                </div>

                <!-- Dashboard Section -->
                <div class="tab-pane fade show active" id="dashboard-section">
                    
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
                                                <span id="booking-trend">Loading...</span>
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
                                            <h6 class="text-muted">Total Revenue</h6>
                                            <h3 class="mb-0">₱<?php echo number_format($total_revenue, 2); ?></h3>
                                            <?php if($total_revenue > 0): ?>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>
                                                <span id="revenue-trend">Loading...</span>
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
                                                <span id="buses-status">Loading...</span>
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
                                                <span id="users-trend">Loading...</span>
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
                                            Last 7 Days
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="bookingStatsDropdown">
                                            <li><a class="dropdown-item" href="#" onclick="changeBookingPeriod('7days')">Last 7 Days</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="changeBookingPeriod('30days')">Last 30 Days</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="changeBookingPeriod('90days')">Last 90 Days</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container" style="height: 300px;">
                                        <canvas id="bookingStatsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Popular Routes</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container" style="height: 300px;">
                                        <canvas id="routesChart"></canvas>
                                    </div>
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
                                    <a href="admin/tabs/bookings_admin.php" class="btn btn-sm btn-primary">View All</a>
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
                                                    <td><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($booking['origin'] . ' to ' . $booking['destination']); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($booking['travel_date'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $booking['status'];
                                                        $badge_class = '';
                                                        $display_text = '';
                                                        
                                                        switch($status) {
                                                            case 'confirmed':
                                                                $badge_class = 'bg-success';
                                                                $display_text = 'Confirmed';
                                                                break;
                                                            case 'pending':
                                                                $badge_class = 'bg-warning text-dark';
                                                                $display_text = 'Pending';
                                                                break;
                                                            case 'cancelled':
                                                                $badge_class = 'bg-danger';
                                                                $display_text = 'Cancelled';
                                                                break;
                                                            default:
                                                                $badge_class = 'bg-secondary';
                                                                $display_text = ucfirst($status);
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $display_text; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="admin/tabs/bookings_admin.php?action=view&id=<?php echo $booking['id']; ?>" 
                                                               class="btn btn-outline-primary" data-bs-toggle="tooltip" title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php if($booking['status'] == 'pending'): ?>
                                                            <button type="button" class="btn btn-outline-success" 
                                                                    onclick="updateBookingStatus(<?php echo $booking['id']; ?>, 'confirmed')" 
                                                                    data-bs-toggle="tooltip" title="Confirm">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger" 
                                                                    onclick="updateBookingStatus(<?php echo $booking['id']; ?>, 'cancelled')" 
                                                                    data-bs-toggle="tooltip" title="Cancel">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                            <?php endif; ?>
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
                                                    <strong><?php echo htmlspecialchars($schedule['origin'] . ' to ' . $schedule['destination']); ?></strong>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-clock me-1"></i> <?php echo date('h:i A', strtotime($schedule['departure_time'])); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-bus me-1"></i> <?php echo htmlspecialchars($schedule['bus_name']); ?>
                                                        <?php if(isset($schedule['trip_number'])): ?>
                                                            <span class="badge bg-info ms-1"><?php echo htmlspecialchars($schedule['trip_number']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-center">
                                                    <button class="btn btn-sm btn-outline-secondary" 
                                                            onclick="viewScheduleDetails(<?php echo $schedule['id']; ?>)">
                                                        Details
                                                    </button>
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
                                    <a href="admin/tabs/schedules_admin.php" class="btn btn-sm btn-primary">Manage Schedules</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
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

        // Initialize Charts
        let bookingStatsChart;
        let routesChart;

        // Booking Statistics Chart
        const bookingStatsCanvas = document.getElementById('bookingStatsChart');
        if (bookingStatsCanvas) {
            const bookingStatsCtx = bookingStatsCanvas.getContext('2d');
            bookingStatsChart = new Chart(bookingStatsCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Bookings',
                        data: [],
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(54, 162, 235, 1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Bookings: ${context.parsed.y}`;
                                }
                            }
                        },
                        // Add data labels plugin
                        datalabels: {
                            display: true,
                            align: 'top',
                            offset: 10,
                            backgroundColor: 'rgba(54, 162, 235, 0.8)',
                            borderRadius: 4,
                            color: 'white',
                            font: {
                                weight: 'bold'
                            },
                            formatter: function(value, context) {
                                // Calculate percentage change from previous day
                                const dataIndex = context.dataIndex;
                                const dataset = context.dataset.data;
                                
                                if (dataIndex === 0) {
                                    return value; // First data point, no previous value
                                }
                                
                                const previousValue = dataset[dataIndex - 1];
                                let percentageChange = 0;
                                
                                if (previousValue > 0) {
                                    percentageChange = ((value - previousValue) / previousValue) * 100;
                                } else {
                                    percentageChange = value > 0 ? 100 : 0;
                                }
                                
                                const sign = percentageChange >= 0 ? '+' : '';
                                return `${value} (${sign}${percentageChange.toFixed(1)}%)`;
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        }

        // Popular Routes Chart
        const routesCanvas = document.getElementById('routesChart');
        if (routesCanvas) {
            try {
                const routesCtx = routesCanvas.getContext('2d');
                routesChart = new Chart(routesCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [],
                        datasets: [{
                            data: [],
                            backgroundColor: [
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(153, 102, 255, 0.7)'
                            ],
                            borderColor: [
                                'rgba(255, 99, 132, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)'
                            ],
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
                            },
                            // Add empty data message
                            emptyData: {
                                text: 'No data available',
                                color: '#999',
                                fontStyle: 'italic',
                                display: true
                            }
                        }
                    }
                });

                // Set empty data state initially
                routesChart.data.labels = ['No data'];
                routesChart.data.datasets[0].data = [1]; // Need at least 1 value for doughnut chart
                routesChart.update();
            } catch (e) {
                console.error("Routes chart initialization error:", e);
            }
        }

        // Fetch Dashboard Data
        async function fetchDashboardData(period = '7days') {
            try {
                const response = await fetch(`backend/connections/get_stats_trends.php?period=${period}`);
                const data = await response.json();
                console.log("API Response:", data);
                
                // Update booking stats chart
                if (data.bookingStats && bookingStatsChart) {
                    // Format dates properly
                    const formattedData = data.bookingStats.map(item => ({
                        x: item.date, // The actual date string from your database
                        y: item.count
                    }));
                    
                    bookingStatsChart.data.labels = data.bookingStats.map(item => item.date);
                    bookingStatsChart.data.datasets[0].data = data.bookingStats.map(item => item.count);
                    bookingStatsChart.update();
                } else {
                    console.log("Booking stats data missing or chart not initialized");
                }
                
                // Update popular routes chart
                if (data.popularRoutes && routesChart) {
                    routesChart.data.labels = data.popularRoutes.map(item => item.route);
                    routesChart.data.datasets[0].data = data.popularRoutes.map(item => item.count);
                    routesChart.update();
                }
                
                // Update trends
                if (data.trends) {
                    if (document.getElementById('booking-trend')) {
                        const bookingTrend = data.trends.bookingTrend;
                        const bookingTrendElement = document.getElementById('booking-trend');
                        bookingTrendElement.textContent = `${Math.abs(bookingTrend)}% from yesterday`;
                        bookingTrendElement.parentElement.className = bookingTrend >= 0 ? 'text-success mb-0' : 'text-danger mb-0';
                        bookingTrendElement.parentElement.innerHTML = `<i class="fas fa-arrow-${bookingTrend >= 0 ? 'up' : 'down'} me-1"></i>` + bookingTrendElement.outerHTML;
                    }
                    
                    if (document.getElementById('revenue-trend')) {
                        const revenueTrend = data.trends.revenueTrend;
                        const revenueTrendElement = document.getElementById('revenue-trend');
                        revenueTrendElement.textContent = `${Math.abs(revenueTrend)}% from yesterday`;
                        revenueTrendElement.parentElement.className = revenueTrend >= 0 ? 'text-success mb-0' : 'text-danger mb-0';
                        revenueTrendElement.parentElement.innerHTML = `<i class="fas fa-arrow-${revenueTrend >= 0 ? 'up' : 'down'} me-1"></i>` + revenueTrendElement.outerHTML;
                    }
                    
                    if (document.getElementById('buses-status')) {
                        document.getElementById('buses-status').textContent = `${data.trends.busesActive} buses operational`;
                    }
                    
                    if (document.getElementById('users-trend')) {
                        const usersTrend = data.trends.usersTrend;
                        const usersTrendElement = document.getElementById('users-trend');
                        usersTrendElement.textContent = `${Math.abs(usersTrend)}% from yesterday`;
                        usersTrendElement.parentElement.className = usersTrend >= 0 ? 'text-success mb-0' : 'text-danger mb-0';
                        usersTrendElement.parentElement.innerHTML = `<i class="fas fa-arrow-${usersTrend >= 0 ? 'up' : 'down'} me-1"></i>` + usersTrendElement.outerHTML;
                    }
                }
            } catch (error) {
                console.error('Error fetching dashboard data:', error);
            }
        }

        // Change booking period
        function changeBookingPeriod(period) {
            fetchDashboardData(period);
            const dropdownButton = document.getElementById('bookingStatsDropdown');
            switch(period) {
                case '7days':
                    dropdownButton.textContent = 'Last 7 Days';
                    break;
                case '30days':
                    dropdownButton.textContent = 'Last 30 Days';
                    break;
                case '90days':
                    dropdownButton.textContent = 'Last 90 Days';
                    break;
            }
        }

        // Update booking status
        async function updateBookingStatus(bookingId, status) {
            if (confirm(`Are you sure you want to ${status} this booking?`)) {
                try {
                    const response = await fetch('backend/connections/update_booking_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            booking_id: bookingId,
                            status: status
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Reload the page to show updated data
                        location.reload();
                    } else {
                        alert('Error updating booking status: ' + data.message);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error updating booking status');
                }
            }
        }

        // View schedule details
        function viewScheduleDetails(scheduleId) {
            // Redirect to schedules page with specific schedule
            window.location.href = `admin/tabs/schedules_admin.php?action=view&id=${scheduleId}`;
        }

        // Initialize dashboard data on page load
        document.addEventListener('DOMContentLoaded', function() {
            fetchDashboardData('7days');
            
            // Refresh data every 5 minutes
            setInterval(() => {
                fetchDashboardData('7days');
            }, 300000);
        });

        // Add auto-refresh for notifications
        function refreshNotifications() {
            fetch('admin/api/get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const notificationDropdown = document.getElementById('notificationDropdown');
                    const badge = notificationDropdown.querySelector('.badge');
                    
                    if (data.count > 0) {
                        if (!badge) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'badge bg-danger';
                            newBadge.textContent = data.count;
                            notificationDropdown.appendChild(newBadge);
                        } else {
                            badge.textContent = data.count;
                        }
                    } else if (badge) {
                        badge.remove();
                    }
                })
                .catch(error => console.error('Error refreshing notifications:', error));
        }

        // Refresh notifications every minute
        setInterval(refreshNotifications, 60000);
    </script>
</body>
</html>