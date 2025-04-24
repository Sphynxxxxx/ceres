<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "booking_system";

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$seat_id = $_POST['seat_id'];

$sql = "UPDATE seats SET status = 'unavailable' WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $seat_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false]);
}

$stmt->close();
$conn->close();
?>
