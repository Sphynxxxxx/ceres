<?php
$servername = "localhost";
$username = "root";
$password = ""; 
$database = "booking_system";

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT status FROM seats";
$result = $conn->query($sql);

$seats = [];
while ($row = $result->fetch_assoc()) {
    $seats[] = $row['status'];
}

echo json_encode($seats);

$conn->close();
?>
