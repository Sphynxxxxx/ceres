<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISAT-U Ceres Bus Ticket System - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
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
                    <a class="nav-link active" href="#dashboard-section" data-bs-toggle="tab">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#bookings-section" data-bs-toggle="tab">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#routes-section" data-bs-toggle="tab">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#schedules-section" data-bs-toggle="tab">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#buses-section" data-bs-toggle="tab">
                        <i class="fas fa-bus"></i>
                        <span>Buses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#users-section" data-bs-toggle="tab">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#reports-section" data-bs-toggle="tab">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#announcements-section" data-bs-toggle="tab">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#settings-section" data-bs-toggle="tab">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="nav-item mt-5">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
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
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">3</span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                    <li><h6 class="dropdown-header">Notifications</h6></li>
                                    <li><a class="dropdown-item" href="#">New booking received</a></li>
                                    <li><a class="dropdown-item" href="#">Schedule updated</a></li>
                                    <li><a class="dropdown-item" href="#">Low seat availability alert</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#">See all notifications</a></li>
                                </ul>
                            </li>
                            <li class="nav-item dropdown profile-section">
                                <a class="nav-link dropdown-toggle" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <img src="/api/placeholder/40/40" alt="Admin">
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                                    <li><h6 class="dropdown-header">Admin User</h6></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Settings</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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
                                            <h3 class="mb-0">1,254</h3>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>12% increase</p>
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
                                            <h3 class="mb-0">₱24,500</h3>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>8% increase</p>
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
                                            <h3 class="mb-0">18</h3>
                                            <p class="text-danger mb-0"><i class="fas fa-arrow-down me-1"></i>2 under maintenance</p>
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
                                            <h3 class="mb-0">758</h3>
                                            <p class="text-success mb-0"><i class="fas fa-arrow-up me-1"></i>24 new today</p>
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
                                        <!-- Placeholder for chart -->
                                        <img src="/api/placeholder/700/300" alt="Booking Statistics Chart" class="img-fluid">
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
                                    <div class="chart-container">
                                        <!-- Placeholder for chart -->
                                        <img src="/api/placeholder/300/300" alt="Popular Routes Chart" class="img-fluid">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Bookings -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Recent Bookings</h5>
                                    <a href="#bookings-section" class="btn btn-sm btn-primary" data-bs-toggle="tab">View All</a>
                                </div>
                                <div class="card-body">
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
                                                
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Upcoming Schedules</h5>
                                </div>
                                <div class="card-body">
                                    
                                </div>
                                <div class="card-footer text-center">
                                    <a href="#schedules-section" class="btn btn-sm btn-primary" data-bs-toggle="tab">View All Schedules</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                    
            </div>
        </div>
    </div>

    <!-- JavaScript Bundle with Popper -->
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
    </script>
</body>
</html>
                   
                                        