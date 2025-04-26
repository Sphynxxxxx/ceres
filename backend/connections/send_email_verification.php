<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
session_start();

require_once "../../vendor/autoload.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate email
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        echo json_encode(['success' => false, 'error' => 'Invalid email']);
        exit;
    }

    // Generate random 6-digit code
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Store in session with expiration
    $_SESSION['verification'] = [
        'code' => $code,
        'email' => $email,
        'expires' => time() + 1800 // 30 minutes from now
    ];

    // Send email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'larrydenverbiaco@gmail.com'; 
        $mail->Password = 'cpnj axtl trss bgtb';    
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('larrydenverbiaco@gmail.com', 'Your Name');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification Code';
        $mail->Body = "Your verification code is: <b>$code</b>";

        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'error' => 'Failed to send email']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>