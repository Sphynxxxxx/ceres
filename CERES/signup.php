<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "user_system";


$conn = new mysqli($servername, $username, $password, $database);


if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed."]));
}

$name = $_POST['name'];
$lastName = $_POST['lastName'];
$email = $_POST['email'];
$contact = $_POST['contact'];

$checkQuery = "SELECT * FROM users WHERE email = ?";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Email is already registered."]);
    exit();
}

$query = "INSERT INTO users (first_name, last_name, email, contact, password) VALUES (?, ?, ?, ?, ?)";
$stmt = $conn->prepare($query);

$defaultPassword = password_hash("default123", PASSWORD_BCRYPT);

$stmt->bind_param("sssss", $name, $lastName, $email, $contact, $defaultPassword);
if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => "Signup failed. Try again."]);
}

$stmt->close();
$conn->close();
?>
