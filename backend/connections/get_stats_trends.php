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
        $date_condition = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case '30days':
        $date_condition = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case '90days':
        $date_condition = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        break;
    default:
        $date_condition = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
}

// Get booking statistics
$query = "SELECT DATE(created_at) as date, COUNT(*) as count 
          FROM bookings 
          WHERE $date_condition
          GROUP BY DATE(created_at)
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
$query = "SELECT CONCAT(b.origin, ' - ', b.destination) as route, 
          COUNT(*) as count 
          FROM bookings bk
          JOIN buses b ON bk.bus_id = b.id
          GROUP BY b.origin, b.destination
          ORDER BY count DESC
          LIMIT 5";

$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $response['popularRoutes'][] = [
            'route' => $row['route'],
            'count' => (int)$row['count']
        ];
    }
}

$query = "SELECT 
    (SELECT COUNT(*) FROM bookings WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as current_month,
    (SELECT COUNT(*) FROM bookings WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))) as last_month";

$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    if ($data['last_month'] > 0) {
        $response['trends']['bookingTrend'] = round((($data['current_month'] - $data['last_month']) / $data['last_month']) * 100, 1);
    } else {
        $response['trends']['bookingTrend'] = $data['current_month'] > 0 ? 100 : 0;
    }
}

// Revenue trend (today vs yesterday)
$query = "SELECT 
    (SELECT COALESCE(SUM(fare), 0) FROM bookings WHERE DATE(created_at) = CURDATE()) as today,
    (SELECT COALESCE(SUM(fare), 0) FROM bookings WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) as yesterday";

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
    $response['trends']['busesActive'] = $data['active'];
}

// Users trend (this month vs last month)
$query = "SELECT 
    (SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as current_month,
    (SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))) as last_month";

$result = $conn->query($query);
if ($result) {
    $data = $result->fetch_assoc();
    if ($data['last_month'] > 0) {
        $response['trends']['usersTrend'] = round((($data['current_month'] - $data['last_month']) / $data['last_month']) * 100, 1);
    } else {
        $response['trends']['usersTrend'] = $data['current_month'] > 0 ? 100 : 0;
    }
}

echo json_encode($response);
?>