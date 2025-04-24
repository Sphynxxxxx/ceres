<?php
$servername = "localhost";
$username = "root"; 
$password = ""; 
$dbname = "user_registration";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$name = $_POST['name'];
$lastName = $_POST['lastName'];
$email = $_POST['email'];
$contact = $_POST['contact'];

$stmt = $conn->prepare("INSERT INTO users (name, last_name, email, contact) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $name, $lastName, $email, $contact);

if ($stmt->execute()) {
    echo "success";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
