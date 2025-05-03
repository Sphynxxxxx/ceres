<?php
header('Content-Type: application/json');
require_once "config.php";

$period = isset($_GET['period']) ? $_GET['period'] : '7days';
$response = [
    'bookingStats' => [],
    'popularRoutes' => [],
    'trends' => []
];

// Get date range based on period
$date_condition = '';
switch($period) {
    case '7days':
        $date_condition = "DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case '30days':
        $date_condition = "DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case '90days':
        $date_condition = "DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        break;
    default:
        $date_condition = "DATE(b.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
}

// Get booking statistics (daily)
$query = "SELECT DATE(b.created_at) as date, COUNT(*) as count 
          FROM bookings b
          WHERE $date_condition
          AND b.booking_status = 'confirmed'
          GROUP BY DATE(b.created_at)
          ORDER BY date ASC";

$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $response['bookingStats'][] = [
            'date' => date('M d', strtotime($row['date'])),
            'count' => (int)$row['count']
        ];
    }
}

// Get popular routes
$query = "SELECT CONCAT(r.origin, ' - ', r.destination) as route, 
          COUNT(*) as count,
          SUM(r.fare) as total_revenue
          FROM bookings b
          JOIN buses bus ON b.bus_id = bus.id
          JOIN routes r ON bus.route_id = r.id
          WHERE b.payment_status = 'verified'
          GROUP BY r.origin, r.destination
          ORDER BY count DESC
          LIMIT 5";

$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $response['popularRoutes'][] = [
            'route' => $row['route'],
            'count' => (int)$row['count'],
            'revenue' => (float)$row['total_revenue']
        ];
    }
}

// Daily booking trend (today vs yesterday)
$query = "SELECT 
    (SELECT COUNT(*) FROM bookings 
     WHERE DATE(created_at) = CURDATE()) as today,
     
    (SELECT COUNT(*) FROM bookings 
     WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) as yesterday";

$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    if ($data['yesterday'] > 0) {
        $response['trends']['bookingTrend'] = round((($data['today'] - $data['yesterday']) / $data['yesterday']) * 100, 1);
    } else {
        $response['trends']['bookingTrend'] = $data['today'] > 0 ? 100 : 0;
    }
}

// Daily revenue trend (today vs yesterday)
$query = "SELECT 
    (SELECT COALESCE(SUM(r.fare), 0) 
     FROM bookings b
     JOIN buses bus ON b.bus_id = bus.id
     JOIN routes r ON bus.route_id = r.id
     WHERE b.payment_status = 'verified'
     AND DATE(b.created_at) = CURDATE()) as today,
     
    (SELECT COALESCE(SUM(r.fare), 0) 
     FROM bookings b
     JOIN buses bus ON b.bus_id = bus.id
     JOIN routes r ON bus.route_id = r.id
     WHERE b.payment_status = 'verified'
     AND DATE(b.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) as yesterday";

$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    if ($data['yesterday'] > 0) {
        $response['trends']['revenueTrend'] = round((($data['today'] - $data['yesterday']) / $data['yesterday']) * 100, 1);
    } else {
        $response['trends']['revenueTrend'] = $data['today'] > 0 ? 100 : 0;
    }
}

// Active buses count
$query = "SELECT COUNT(*) as active FROM buses WHERE status = 'Active'";
$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    $response['trends']['busesActive'] = (int)$data['active'];
}

// Daily users trend (registered today vs yesterday)
$query = "SELECT 
    (SELECT COUNT(*) FROM users 
     WHERE DATE(created_at) = CURDATE()) as today,
     
    (SELECT COUNT(*) FROM users 
     WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) as yesterday";

$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    if ($data['yesterday'] > 0) {
        $response['trends']['usersTrend'] = round((($data['today'] - $data['yesterday']) / $data['yesterday']) * 100, 1);
    } else {
        $response['trends']['usersTrend'] = $data['today'] > 0 ? 100 : 0;
    }
}

echo json_encode($response);
?>