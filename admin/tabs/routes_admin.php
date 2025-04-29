<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../backend/connections/config.php";

// Handle route deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_route') {
    $route_id = isset($_POST['route_id']) ? intval($_POST['route_id']) : 0;
    
    if ($route_id > 0) {
        // Prepare and execute delete query
        $delete_query = "DELETE FROM routes WHERE id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("i", $route_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Route successfully deleted.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error deleting route: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
        
        $stmt->close();
        
        // Redirect to prevent form resubmission
        header("Location: routes_admin.php");
        exit();
    }
}

// Handle route addition/editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && 
    ($_POST['action'] === 'add_route' || $_POST['action'] === 'edit_route')) {
    $origin = mysqli_real_escape_string($conn, $_POST['origin']);
    $destination = mysqli_real_escape_string($conn, $_POST['destination']);
    $distance = floatval($_POST['distance']);
    $estimated_duration = mysqli_real_escape_string($conn, $_POST['estimated_duration']);
    $fare = floatval($_POST['fare']);

    if ($_POST['action'] === 'add_route') {
        // Insert new route
        $insert_query = "INSERT INTO routes (origin, destination, distance, estimated_duration, fare) 
                         VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ssdsd", $origin, $destination, $distance, $estimated_duration, $fare);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Route successfully added.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error adding route: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
    } else {
        // Edit existing route
        $route_id = intval($_POST['route_id']);
        $update_query = "UPDATE routes SET origin = ?, destination = ?, distance = ?, estimated_duration = ?, fare = ? 
                         WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("ssdsdi", $origin, $destination, $distance, $estimated_duration, $fare, $route_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Route successfully updated.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error updating route: " . $conn->error;
            $_SESSION['message_type'] = "danger";
        }
    }
    
    $stmt->close();
    
    // Redirect to prevent form resubmission
    header("Location: routes_admin.php");
    exit();
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
    $search_condition = " WHERE origin LIKE '%$search%' OR destination LIKE '%$search%'";
}

// Get total number of routes
$total_query = "SELECT COUNT(*) as total FROM routes" . $search_condition;
$total_result = $conn->query($total_query);
$total_records = 0;
if ($total_result && $total_result->num_rows > 0) {
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
}
$total_pages = ceil($total_records / $records_per_page);

// Get routes with pagination
$routes = [];
try {
    $query = "SELECT id, origin, destination, distance, estimated_duration, fare, created_at 
              FROM routes" . $search_condition . " 
              ORDER BY created_at DESC 
              LIMIT $start_from, $records_per_page";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $routes[] = $row;
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
    <title>Routes Management - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .route-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        
        .route-icon {
            background-color: #f0f2f5;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .route-details {
            display: flex;
            align-items: center;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header d-flex align-items-center">
                <i class="fas fa-bus-alt me-2 fs-4"></i>
                <h4 class="mb-0">Admin Panel</h4>
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
                    <a class="nav-link active" href="routes_admin.php">
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
                    <a class="nav-link" href="reports_admin.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="announcements_admin.php">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings_admin.php">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="nav-item mt-5">
                    <a class="nav-link text-danger" href="index.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Exit to Main Site</span>
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
                        <form class="d-flex ms-auto" action="routes_admin.php" method="GET">
                            <div class="input-group">
                                <input class="form-control" type="search" name="search" placeholder="Search routes" value="<?php echo htmlspecialchars($search); ?>" aria-label="Search">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                        <ul class="navbar-nav ms-3">
                            <li class="nav-item">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRouteModal">
                                    <i class="fas fa-plus me-2"></i>Add Route
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-route me-2"></i>Route Management</h2>
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

                <!-- Routes Table -->
                <div class="card route-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Available Routes</h5>
                            <span class="badge bg-primary"><?php echo $total_records; ?> routes found</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($routes) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Origin</th>
                                        <th>Destination</th>
                                        <th>Distance (km)</th>
                                        <th>Est. Duration</th>
                                        <th>Fare (₱)</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($routes as $route): ?>
                                    <tr>
                                        <td>#<?php echo $route['id']; ?></td>
                                        <td>
                                            <div class="route-details">
                                                <div class="route-icon">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                </div>
                                                <?php echo htmlspecialchars($route['origin']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="route-details">
                                                <div class="route-icon">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                </div>
                                                <?php echo htmlspecialchars($route['destination']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo number_format($route['distance'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($route['estimated_duration']); ?></td>
                                        <td>₱<?php echo number_format($route['fare'], 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($route['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-outline-primary btn-sm edit-route" 
                                                    data-id="<?php echo $route['id']; ?>"
                                                    data-origin="<?php echo htmlspecialchars($route['origin']); ?>"
                                                    data-destination="<?php echo htmlspecialchars($route['destination']); ?>"
                                                    data-distance="<?php echo $route['distance']; ?>"
                                                    data-duration="<?php echo htmlspecialchars($route['estimated_duration']); ?>"
                                                    data-fare="<?php echo $route['fare']; ?>"
                                                    data-bs-toggle="tooltip" title="Edit Route">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm delete-route" 
                                                    data-id="<?php echo $route['id']; ?>"
                                                    data-bs-toggle="tooltip" title="Delete Route">
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
                            <i class="fas fa-info-circle me-2"></i>No routes found. 
                            <?php if (!empty($search)): ?>
                            Try a different search term or <a href="routes_admin.php" class="alert-link">clear the search</a>.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Route Modal -->
    <div class="modal fade" id="addRouteModal" tabindex="-1" aria-labelledby="addRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addRouteModalLabel"><i class="fas fa-plus me-2"></i>Add New Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addRouteForm" method="post" action="routes_admin.php">
                        <input type="hidden" name="action" value="add_route">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="origin" class="form-label">Origin*</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="origin" name="origin" required placeholder="Enter origin location">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="destination" class="form-label">Destination*</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="destination" name="destination" required placeholder="Enter destination location">
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="distance" class="form-label">Distance (km)*</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" class="form-control" id="distance" name="distance" required placeholder="Route distance">
                                    <span class="input-group-text">km</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="estimated_duration" class="form-label">Estimated Duration*</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="estimated_duration" name="estimated_duration" required placeholder="e.g., 2h 30m">
                                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="fare" class="form-label">Fare*</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control" id="fare" name="fare" required placeholder="Route fare">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="addRouteForm" class="btn btn-primary">Add Route</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Route Modal -->
    <div class="modal fade" id="editRouteModal" tabindex="-1" aria-labelledby="editRouteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRouteModalLabel"><i class="fas fa-edit me-2"></i>Edit Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editRouteForm" method="post" action="routes_admin.php">
                        <input type="hidden" name="action" value="edit_route">
                        <input type="hidden" id="edit_route_id" name="route_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_origin" class="form-label">Origin*</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="edit_origin" name="origin" required placeholder="Enter origin location">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_destination" class="form-label">Destination*</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" class="form-control" id="edit_destination" name="destination" required placeholder="Enter destination location">
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="edit_distance" class="form-label">Distance (km)*</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" class="form-control" id="edit_distance" name="distance" required placeholder="Route distance">
                                    <span class="input-group-text">km</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_estimated_duration" class="form-label">Estimated Duration*</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="edit_estimated_duration" name="estimated_duration" required placeholder="e.g., 2h 30m">
                                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_fare" class="form-label">Fare*</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control" id="edit_fare" name="fare" required placeholder="Route fare">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editRouteForm" class="btn btn-primary">Update Route</button>
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

        // Delete route functionality
        document.querySelectorAll('.delete-route').forEach(function(button) {
            button.addEventListener('click', function() {
                const routeId = this.getAttribute('data-id');
                if (confirm('Are you sure you want to delete route #' + routeId + '? This action cannot be undone.')) {
                    // Create a form to submit delete request
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'routes_admin.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_route';
                    form.appendChild(actionInput);
                    
                    const routeIdInput = document.createElement('input');
                    routeIdInput.type = 'hidden';
                    routeIdInput.name = 'route_id';
                    routeIdInput.value = routeId;
                    form.appendChild(routeIdInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });

        // Edit route functionality
        document.querySelectorAll('.edit-route').forEach(function(button) {
            button.addEventListener('click', function() {
                // Populate edit modal with current route details
                document.getElementById('edit_route_id').value = this.getAttribute('data-id');
                document.getElementById('edit_origin').value = this.getAttribute('data-origin');
                document.getElementById('edit_destination').value = this.getAttribute('data-destination');
                document.getElementById('edit_distance').value = this.getAttribute('data-distance');
                document.getElementById('edit_estimated_duration').value = this.getAttribute('data-duration');
                document.getElementById('edit_fare').value = this.getAttribute('data-fare');

                // Show the edit modal
                var editRouteModal = new bootstrap.Modal(document.getElementById('editRouteModal'));
                editRouteModal.show();
            });
        });
    </script>
</body>
</html>