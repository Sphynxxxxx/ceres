<?php

$host = "localhost"; 
$username = "root";  
$password = ""; 
$database = "isatu_ceres"; 

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $payment_method = $_POST['payment_method'] ?? '';
    $promo_code = $_POST['promo_code'] ?? '';
    $invoice_required = isset($_POST['invoice']) ? 1 : 0;
    $company_name = $_POST['company'] ?? '';
    $post_code = $_POST['post_code'] ?? '';

    if (empty($payment_method)) {
        echo "<script>alert('Payment unsuccessful: No payment method selected.'); window.location.href='indexPayment1.php';</script>";
        exit;
    }

    $payment_success = rand(0, 1);

    $sql = "INSERT INTO payments (payment_method, promo_code, company_name, post_code, invoice_required) 
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssi", $payment_method, $promo_code, $company_name, $post_code, $invoice_required);

    if ($stmt->execute() && $payment_success) {
        echo "<script>alert('Payment successful! Details saved.'); window.location.href='success_page.php';</script>";
    } else {
        echo "<script>alert('Payment unsuccessful. Please try again.'); window.location.href='payment_form.php';</script>";
    }

    $stmt->close();
} else {
    echo "<script>alert('Invalid request.'); window.location.href='payment_form.php';</script>";
}

$conn->close();
?>
