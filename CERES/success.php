<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "isatu_ceres";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['confirmed_schedule'])) {
    die("No booking found.");
}
$booking = $_SESSION['confirmed_schedule'];

$sql = "INSERT INTO bookings (bus_no, departure, origin, destination, distance, total_fare, contact) 
        VALUES (?, ?, ?, ?, ?, ?, ?);";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssss", 
    $booking['bus'], 
    $booking['departure'], 
    $booking['origin'], 
    $booking['destination'], 
    $booking['distance'], 
    $booking['total_fare'], 
    $booking['contact']
);

if ($stmt->execute()) {
    unset($_SESSION['confirmed_schedule']);
    $message = "Your booking has been successfully confirmed!";
} else {
    $message = "Error: " . $conn->error;
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Booking Confirmed</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background-color: #f8f9fa; }
        .message { font-size: 24px; color: #28a745; }
        .back-button { margin-top: 20px; padding: 10px 20px; font-size: 16px; border: none; background-color: #003366; color: white; cursor: pointer; border-radius: 5px; }
        .back-button:hover { background-color: #002147; }
    </style>
</head>
<body>
    <h2>Successfully Confirmed</h2>
    <p class="message">✅ <?= htmlspecialchars($message) ?></p>
    <button onclick="window.location.href='indexBookingScreen.php'">Back</button>