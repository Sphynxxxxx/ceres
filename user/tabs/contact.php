<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Redirect to login page
    header("Location: login.php");
    exit;
}

// Get user info from session
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Database connection
require_once "../../backend/connections/config.php"; 
require_once "../../vendor/autoload.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Validate input
    $errors = array();
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required";
    }
    if (empty($subject)) {
        $errors[] = "Subject is required";
    }
    if (empty($message)) {
        $errors[] = "Message is required";
    }
    
    if (empty($errors)) {
        // Save message to database or send email
        // For now, we'll just save to a messages table
        $stmt = $conn->prepare("INSERT INTO contact_messages (user_id, name, email, subject, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("issss", $user_id, $name, $email, $subject, $message);
            if ($stmt->execute()) {
                $success_message = "Your message has been sent successfully! We'll get back to you soon.";
                // Clear form fields
                $_POST = array();
            } else {
                $error_message = "Failed to send message. Please try again.";
            }
            $stmt->close();
        } else {
            $error_message = "Database error. Please try again later.";
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Fetch user data from database
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user_data = $result->fetch_assoc();
        } else {
            // User not found in database
            session_destroy();
            header("Location: login.php?error=invalid_user");
            exit;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    // Handle exception
    error_log("Error fetching user data: " . $e->getMessage());
}

// Fetch user's messages and their replies
$user_messages = [];
try {
    $query = "SELECT cm.*, 
                     cr.reply_message, 
                     cr.replied_by, 
                     cr.replied_at 
              FROM contact_messages cm 
              LEFT JOIN contact_replies cr ON cm.id = cr.message_id 
              WHERE cm.user_id = ? 
              ORDER BY cm.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_messages[] = $row;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching messages: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - ISAT-U Ceres Bus Ticket System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../css/user.css" rel="stylesheet">
    <link href="../css/navfot.css" rel="stylesheet">
    <style>
        .contact-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .contact-info-card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            height: 100%;
            transition: transform 0.3s ease;
        }
        
        .contact-info-card:hover {
            transform: translateY(-5px);
        }
        
        .contact-info-icon {
            width: 60px;
            height: 60px;
            background-color: #ffc107;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .contact-info-icon i {
            font-size: 24px;
            color: #2c3e50;
        }
        
        .map-container {
            height: 400px;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .form-control:focus {
            border-color: #ffc107;
            box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25);
        }
        
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #d39e00;
            color: #212529;
        }
        
        .social-links a {
            font-size: 24px;
            margin: 0 10px;
            color: #ffffff;
            transition: color 0.3s ease;
        }
        
        .social-links a:hover {
            color: #ffc107;
        }
        
        .message-history-card {
            border-left: 4px solid #0d6efd;
            margin-bottom: 15px;
        }
        
        .message-history-card.unread {
            border-left-color: #ffc107;
        }
        
        .reply-box {
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-top: 15px;
        }
        
        .message-date {
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="../dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="routes.php">Routes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">Schedule</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="booking.php">Book Ticket</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="contact.php">Contact</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Contact Header -->
    <div class="contact-header">
        <div class="container text-center">
            <h1 class="display-4">Contact Us</h1>
            <p class="lead">Get in touch with us for any inquiries about our bus services</p>
        </div>
    </div>

    <div class="container my-5">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>


        <div class="row">
            <!-- Contact Information -->
            <div class="col-lg-4 mb-4">
                <div class="card contact-info-card">
                    <div class="card-body text-center">
                        <div class="contact-info-icon mx-auto">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h5 class="card-title">Visit Us</h5>
                        <p class="card-text">
                            Ceres Northbound Terminal<br>
                            Iloilo City, Philippines<br>
                            5000
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="card contact-info-card">
                    <div class="card-body text-center">
                        <div class="contact-info-icon mx-auto">
                            <i class="fas fa-phone"></i>
                        </div>
                        <h5 class="card-title">Call Us</h5>
                        <p class="card-text">
                            Main Office: (033) 337-8888<br>
                            Booking Support: (033) 337-9999<br>
                            Customer Service: (033) 337-7777
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="card contact-info-card">
                    <div class="card-body text-center">
                        <div class="contact-info-icon mx-auto">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h5 class="card-title">Email Us</h5>
                        <p class="card-text">
                            General Inquiries: isatucommuters@ceresbus.com<br>
                            Support: support@ceresbus.com<br>
                            Bookings: bookings@ceresbus.com
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-5">
            <!-- Contact Form -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-paper-plane me-2"></i>Send us a Message</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="name" class="form-label">Your Name</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? $user_name); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Your Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? $user_email); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject</label>
                                <input type="text" class="form-control" id="subject" name="subject" 
                                       value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="message" class="form-label">Message</label>
                                <textarea class="form-control" id="message" name="message" rows="5" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" name="send_message" class="btn btn-warning w-100">
                                <i class="fas fa-paper-plane me-2"></i>Send Message
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Office Hours & Social Media -->
            <div class="col-lg-6 mb-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-clock me-2"></i>Office Hours</h4>
                    </div>
                    <div class="card-body" style="background-color: #343a40; color: white;">
                        <table class="table table-borderless text-white">
                            <tbody>
                                <tr>
                                    <td class="text-white">Monday - Friday:</td>
                                    <td class="text-white">6:00 AM - 10:00 PM</td>
                                </tr>
                                <tr>
                                    <td class="text-white">Saturday:</td>
                                    <td class="text-white">6:00 AM - 9:00 PM</td>
                                </tr>
                                <tr>
                                    <td class="text-white">Sunday:</td>
                                    <td class="text-white">7:00 AM - 8:00 PM</td>
                                </tr>
                                <tr>
                                    <td class="text-white">Holidays:</td>
                                    <td class="text-white">8:00 AM - 6:00 PM</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-share-alt me-2"></i>Connect With Us</h4>
                    </div>
                    <div class="card-body text-center" style="background-color: #343a40;">
                        <div class="social-links">
                            <a href="#" class="text-decoration-none text-white"><i class="fab fa-facebook"></i></a>
                            <a href="#" class="text-decoration-none text-white"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="text-decoration-none text-white"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="text-decoration-none text-white"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Map Section -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-map me-2"></i>Find Us</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="map-container">
                            <iframe 
                                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3919.5515382989307!2d122.56978467581045!3d10.75427598956746!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33aee48ff0082e7f%3A0x7a90be5227c50a97!2sCeres%20Northbound%20Terminal%20Iloilo%20City!5e0!3m2!1sen!2sph!4v1714924183673!5m2!1sen!2sph" 
                                width="100%" 
                                height="100%" 
                                style="border:0;" 
                                allowfullscreen="" 
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade">
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas fa-question-circle me-2"></i>Frequently Asked Questions</h4>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                        How can I book a ticket online?
                                    </button>
                                </h2>
                                <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        You can book tickets online by going to our booking page, selecting your route, date, and number of passengers, then proceeding with payment.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                        What are the payment methods accepted?
                                    </button>
                                </h2>
                                <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        We accept various payment methods including GCash, PayMaya, and Pay over the counter.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                        How can I cancel or change my booking?
                                    </button>
                                </h2>
                                <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        You can manage your bookings by going to the "My Bookings" section in your dashboard. Cancellations and changes must be made at least 24 hours before departure.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (count($user_messages) > 0): ?>
        <div class="row mb-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#messageHistoryCollapse" aria-expanded="true" aria-controls="messageHistoryCollapse">
                        <h4 class="mb-0">
                            <i class="fas fa-history me-2"></i>Your Message History
                            <span class="badge bg-primary ms-2"><?php echo count($user_messages); ?></span>
                        </h4>
                        <i class="fas fa-chevron-down" id="collapseIcon"></i>
                    </div>
                    <div id="messageHistoryCollapse" class="collapse show">
                        <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($user_messages as $msg): ?>
                                <div class="card message-history-card <?php echo $msg['is_read'] ? '' : 'unread'; ?>" id="message-<?php echo $msg['id']; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h5 class="mb-1">
                                                    <?php echo htmlspecialchars($msg['subject']); ?>
                                                    <?php if (!$msg['is_read']): ?>
                                                        <span class="badge bg-warning ms-2">Pending</span>
                                                    <?php endif; ?>
                                                </h5>
                                                <div class="message-date">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Sent: <?php echo date('M d, Y h:i A', strtotime($msg['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <?php if ($msg['is_read']): ?>
                                                    <span class="badge bg-success me-2">Read</span>
                                                <?php endif; ?>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light dropdown-toggle" type="button" 
                                                            id="dropdownMenuButton-<?php echo $msg['id']; ?>" 
                                                            data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end" 
                                                        aria-labelledby="dropdownMenuButton-<?php echo $msg['id']; ?>">
                                                        <li>
                                                            <a class="dropdown-item text-danger" href="#" 
                                                            onclick="deleteMessage(<?php echo $msg['id']; ?>); return false;">
                                                                <i class="fas fa-trash me-2"></i>Delete
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                        
                                        <?php if ($msg['reply_message']): ?>
                                            <div class="reply-box">
                                                <h6 class="mb-2">
                                                    <i class="fas fa-reply me-2"></i>Admin Reply
                                                    <small class="text-muted ms-2">
                                                        from <?php echo htmlspecialchars($msg['replied_by']); ?>
                                                    </small>
                                                </h6>
                                                <p class="mb-1"><?php echo nl2br(htmlspecialchars($msg['reply_message'])); ?></p>
                                                <div class="message-date">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Replied: <?php echo date('M d, Y h:i A', strtotime($msg['replied_at'])); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5>Ceres Bus Ticket System for ISAT-U Commuters</h5>
                    <p>Providing convenient Ceres bus transportation booking for ISAT-U students, faculty, and staff commuters.</p>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="routes.php" class="text-white"><i class="fas fa-route me-2"></i>Routes</a></li>
                        <li><a href="schedule.php" class="text-white"><i class="fas fa-calendar-alt me-2"></i>Schedule</a></li>
                        <li><a href="booking.php" class="text-white"><i class="fas fa-ticket-alt me-2"></i>Book Ticket</a></li>
                        <li><a href="contact.php" class="text-white"><i class="fas fa-envelope me-2"></i>Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 mb-4">
                    <h5>Contact</h5>
                    <address>
                        <i class="fas fa-map-marker-alt me-2"></i> Ceres Northbound Terminal, Iloilo City<br>
                        <i class="fas fa-phone me-2"></i> (033) 337-8888<br>
                        <i class="fas fa-envelope me-2"></i> isatucommuters@ceresbus.com
                    </address>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center">
                <p class="copyright">&copy; 2025 Ceres Bus Terminal - ISAT-U Commuters Ticket System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteMessage(messageId) {
            if (confirm('Are you sure you want to delete this message?')) {
                fetch('../../backend/connections/delete_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: messageId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove the message card from the DOM
                        document.getElementById('message-' + messageId).remove();
                        // Show success message
                        alert('Message deleted successfully!');
                        
                        // Update the message count
                        const messageCount = document.querySelectorAll('.message-history-card').length;
                        const badge = document.querySelector('.card-header .badge');
                        if (badge) {
                            badge.textContent = messageCount;
                        }
                        
                        // If no more messages, reload the page
                        if (messageCount === 0) {
                            location.reload();
                        }
                    } else {
                        alert('Error deleting message: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the message.');
                });
            }
        }

        // Handle collapse icon rotation
        document.addEventListener('DOMContentLoaded', function() {
            const messageHistoryCollapse = document.getElementById('messageHistoryCollapse');
            const collapseIcon = document.getElementById('collapseIcon');
            
            if (messageHistoryCollapse && collapseIcon) {
                messageHistoryCollapse.addEventListener('show.bs.collapse', function () {
                    collapseIcon.style.transform = 'rotate(0deg)';
                });
                
                messageHistoryCollapse.addEventListener('hide.bs.collapse', function () {
                    collapseIcon.style.transform = 'rotate(-90deg)';
                });
            }
        });
    </script>
</body>
</html>