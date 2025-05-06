<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

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

// Get all fare information from the routes table
$fare_info = [];
try {
    $query = "SELECT 
                r.id, 
                r.origin, 
                r.destination, 
                r.distance, 
                r.estimated_duration, 
                r.fare, 
                r.created_at,
                r.updated_at,
                COUNT(DISTINCT b.id) as active_buses,
                COUNT(DISTINCT s.id) as active_schedules
            FROM routes r
            LEFT JOIN buses b ON b.route_name = CONCAT(r.origin, ' → ', r.destination) AND b.status = 'Active'
            LEFT JOIN schedules s ON s.origin = r.origin AND s.destination = r.destination AND s.status = 'active'
            GROUP BY r.id, r.origin, r.destination, r.distance, r.estimated_duration, r.fare, r.created_at, r.updated_at
            ORDER BY r.origin, r.destination";
            
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $fare_info[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching fare information: " . $e->getMessage());
}

// Get fare policies or special rates
$special_rates = [];
try {

    $special_rates = [
        'student' => 20,  // 20% discount
        'senior' => 20,  // 20% discount
        'pwd' => 20,  // 20% discount
        'regular' => 0  // No discount
    ];
} catch (Exception $e) {
    error_log("Error fetching special rates: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fare Information - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">   
    <style>
        .fare-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .fare-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .fare-header {
            background-color: #ffc107;
            color: #212529;
            padding: 15px;
            border-radius: 10px 10px 0 0;
        }
        
        .fare-body {
            padding: 20px;
        }
        
        .route-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .route-cities {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .route-arrow {
            font-size: 1.2rem;
            color: #6c757d;
        }
        
        .fare-amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: #198754;
        }
        
        .fare-details {
            border-top: 1px solid #e9ecef;
            margin-top: 15px;
            padding-top: 15px;
        }
        
        .fare-detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .discount-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .discount-badge {
            background-color: #198754;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .fare-calculator {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .calculate-btn {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
            font-weight: 500;
        }
        
        .calculate-btn:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            color: #212529;
        }
        
        .result-box {
            background-color: #e8f5e9;
            border: 2px solid #4caf50;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }
        
        .service-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background-color: #198754;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .service-badge.limited {
            background-color: #dc3545;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
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
                    <li class="nav-item">
                        <a class="nav-link" href="locations.php">Locations</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="fares.php">Fares</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-tags me-2"></i>Fare Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i> View all fare information for different routes. Special discounts available for students, senior citizens, and PWDs.
                        </div>
                        
                        <!-- Fare Calculator -->
                        <div class="fare-calculator">
                            <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>Fare Calculator</h5>
                            <div class="row">
                                <div class="col-md-3">
                                    <label for="calc-route" class="form-label">Select Route</label>
                                    <select class="form-select" id="calc-route" onchange="updateBaseFare()">
                                        <option value="">Choose a route...</option>
                                        <?php foreach ($fare_info as $route): ?>
                                            <option value="<?php echo $route['fare']; ?>" 
                                                    data-origin="<?php echo htmlspecialchars($route['origin']); ?>" 
                                                    data-destination="<?php echo htmlspecialchars($route['destination']); ?>">
                                                <?php echo htmlspecialchars($route['origin'] . ' → ' . $route['destination']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="calc-passenger-type" class="form-label">Passenger Type</label>
                                    <select class="form-select" id="calc-passenger-type">
                                        <option value="regular">Regular</option>
                                        <option value="student">Student (20% off)</option>
                                        <option value="senior">Senior Citizen (20% off)</option>
                                        <option value="pwd">PWD (20% off)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="calc-quantity" class="form-label">Number of Tickets</label>
                                    <input type="number" class="form-control" id="calc-quantity" min="1" max="10" value="1">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" class="btn calculate-btn w-100" onclick="calculateFare()">
                                        <i class="fas fa-calculator me-2"></i>Calculate
                                    </button>
                                </div>
                            </div>
                            
                            <div id="calculation-result" class="result-box">
                                <h6>Fare Calculation</h6>
                                <div class="fare-detail-item">
                                    <span>Base Fare:</span>
                                    <span id="result-base">₱0.00</span>
                                </div>
                                <div class="fare-detail-item">
                                    <span>Discount:</span>
                                    <span id="result-discount">₱0.00</span>
                                </div>
                                <div class="fare-detail-item">
                                    <span>Quantity:</span>
                                    <span id="result-quantity">1</span>
                                </div>
                                <hr>
                                <div class="fare-detail-item">
                                    <strong>Total Amount:</strong>
                                    <strong id="result-total" class="text-success">₱0.00</strong>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Discount Information -->
                        <div class="discount-card">
                            <h5 class="mb-3"><i class="fas fa-percentage me-2"></i>Special Discounts</h5>
                            <div class="row">
                                <?php foreach ($special_rates as $type => $discount): ?>
                                    <?php if ($discount > 0): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="discount-badge me-3"><?php echo $discount; ?>% OFF</div>
                                            <div>
                                                <h6 class="mb-0"><?php echo ucfirst($type); ?></h6>
                                                <small class="text-muted">Valid ID required</small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Fare Information Cards -->
                        <h5 class="mb-3"><i class="fas fa-route me-2"></i>Route Fares</h5>
                        <div class="row">
                            <?php foreach ($fare_info as $route): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card fare-card">
                                        <div class="fare-header">
                                            <h5 class="mb-0">
                                                <?php echo htmlspecialchars($route['origin']); ?> → <?php echo htmlspecialchars($route['destination']); ?>
                                            </h5>
                                        </div>
                                        <div class="fare-body position-relative">
                                            <?php if ($route['active_schedules'] > 0): ?>
                                                <span class="service-badge">Active Route</span>
                                            <?php else: ?>
                                                <span class="service-badge limited">Unactive Route</span>
                                            <?php endif; ?>
                                            
                                            <div class="text-center mb-3">
                                                <div class="fare-amount">₱<?php echo number_format($route['fare'], 2); ?></div>
                                                <small class="text-muted">Regular Fare</small>
                                            </div>
                                            
                                            <div class="fare-details">
                                                <div class="fare-detail-item">
                                                    <span><i class="fas fa-road me-2"></i>Distance:</span>
                                                    <span><?php echo htmlspecialchars($route['distance']); ?> km</span>
                                                </div>
                                                <div class="fare-detail-item">
                                                    <span><i class="fas fa-clock me-2"></i>Duration:</span>
                                                    <span><?php echo htmlspecialchars($route['estimated_duration']); ?></span>
                                                </div>
                                                <div class="fare-detail-item">
                                                    <span><i class="fas fa-bus me-2"></i>Active Buses:</span>
                                                    <span><?php echo $route['active_buses']; ?></span>
                                                </div>
                                                <div class="fare-detail-item">
                                                    <span><i class="fas fa-calendar-alt me-2"></i>Daily Trips:</span>
                                                    <span><?php echo $route['active_schedules']; ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="text-center mt-3">
                                                <a href="booking.php?origin=<?php echo urlencode($route['origin']); ?>&destination=<?php echo urlencode($route['destination']); ?>" 
                                                   class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-ticket-alt me-2"></i>Book Now
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Fare Policies -->
                        <div class="card mt-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Fare Policies</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-check-circle text-success me-2"></i>All fares are subject to change without prior notice</li>
                                    <li><i class="fas fa-check-circle text-success me-2"></i>Children below 3 years old ride free when accompanied by an adult</li>
                                    <li><i class="fas fa-check-circle text-success me-2"></i>Students must present valid school ID to avail of student discount</li>
                                    <li><i class="fas fa-check-circle text-success me-2"></i>Senior citizens and PWDs must present valid IDs for discount</li>
                                    <li><i class="fas fa-check-circle text-success me-2"></i>Baggage fees may apply for excess luggage</li>
                                    <li><i class="fas fa-check-circle text-success me-2"></i>Refunds are subject to cancellation policies</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                        <li><a href="fares.php" class="text-white"><i class="fas fa-tags me-2"></i>Fares</a></li>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Define discount rates
        const discountRates = {
            'regular': 0,
            'student': 20,
            'senior': 20,
            'pwd': 20
        };
        
        function updateBaseFare() {
            const select = document.getElementById('calc-route');
            const selectedOption = select.options[select.selectedIndex];
            const fare = selectedOption.value;
            
            if (fare) {
                document.getElementById('result-base').textContent = '₱' + parseFloat(fare).toFixed(2);
            }
        }
        
        function calculateFare() {
            const routeSelect = document.getElementById('calc-route');
            const passengerTypeSelect = document.getElementById('calc-passenger-type');
            const quantityInput = document.getElementById('calc-quantity');
            
            if (!routeSelect.value) {
                alert('Please select a route');
                return;
            }
            
            const baseFare = parseFloat(routeSelect.value);
            const passengerType = passengerTypeSelect.value;
            const discountPercent = discountRates[passengerType] || 0;
            const quantity = parseInt(quantityInput.value);
            
            const discountAmount = (baseFare * discountPercent) / 100;
            const discountedFare = baseFare - discountAmount;
            const totalAmount = discountedFare * quantity;
            
            // Update result display
            document.getElementById('result-base').textContent = '₱' + baseFare.toFixed(2);
            document.getElementById('result-discount').textContent = '₱' + discountAmount.toFixed(2);
            document.getElementById('result-quantity').textContent = quantity;
            document.getElementById('result-total').textContent = '₱' + totalAmount.toFixed(2);
            
            // Show result box
            document.getElementById('calculation-result').style.display = 'block';
        }
    </script>
</body>
</html>