<?php
$host = "localhost"; 
$username = "root"; 
$password = ""; 
$dbname = "bus_booking";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$first_name = $_POST['first_name'];
$last_name = $_POST['last_name'];
$num_people = $_POST['num_people'];
$num_seats = $_POST['num_seats'];
$bus_time = $_POST['bus_time'];
$bus_location = $_POST['bus_location'];
$destination = $_POST['destination'];

$sql = "INSERT INTO bookings (first_name, last_name, num_people, num_seats, bus_time, bus_location, destination) 
        VALUES ('$first_name', '$last_name', '$num_people', '$num_seats', '$bus_time', '$bus_location', '$destination')";

if ($conn->query($sql) === TRUE) {
    echo "<script>alert('Booking Successful!'); window.location.href='indexBookingScreen.php';</script>";
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}
$conn->close();
?>
