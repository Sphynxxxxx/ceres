<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once "../../backend/connections/config.php";

// System administrator configuration - no account needed
$admin_id = null; // NULL indicates system/admin reply (no user account required)
$admin_name = "Administrator";

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $message_id = $_POST['message_id'];
    $reply_message = $_POST['reply_message'];
    $reply_to_email = $_POST['reply_to_email'];
    
    // Mark message as read
    $update_stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    $update_stmt->bind_param("i", $message_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Insert reply with NULL for system/admin replies (no user account needed)
    $reply_stmt = $conn->prepare("INSERT INTO contact_replies (message_id, reply_message, replied_by, replied_by_name, replied_at) VALUES (?, ?, ?, ?, NOW())");
    
    // When replied_by is NULL, we need to handle it properly in bind_param
    if ($admin_id === null) {
        $reply_stmt->bind_param("isss", $message_id, $reply_message, $admin_id, $admin_name);
    } else {
        $reply_stmt->bind_param("isis", $message_id, $reply_message, $admin_id, $admin_name);
    }
    
    if ($reply_stmt->execute()) {
        $success_message = "Reply sent successfully to " . $reply_to_email;
    } else {
        $error_message = "Failed to send reply. Please try again.";
    }
    $reply_stmt->close();
}

// Handle mark as read/unread
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $message_id = $_GET['id'];
    $is_read = $_GET['mark_read'] == 1 ? 1 : 0;
    
    $update_stmt = $conn->prepare("UPDATE contact_messages SET is_read = ? WHERE id = ?");
    $update_stmt->bind_param("ii", $is_read, $message_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    header("Location: inquiries.php");
    exit();
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $message_id = $_GET['id'];
    
    $delete_stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
    $delete_stmt->bind_param("i", $message_id);
    
    if ($delete_stmt->execute()) {
        $success_message = "Message deleted successfully.";
    } else {
        $error_message = "Failed to delete message.";
    }
    $delete_stmt->close();
}

// Fetch all messages
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$query = "SELECT cm.*, u.first_name, u.last_name, u.email as user_email 
          FROM contact_messages cm 
          JOIN users u ON cm.user_id = u.id ";

$params = array();
$types = "";

if ($filter === 'unread') {
    $query .= "WHERE cm.is_read = 0 ";
} elseif ($filter === 'read') {
    $query .= "WHERE cm.is_read = 1 ";
} else {
    $query .= "WHERE 1=1 ";
}

if (!empty($search)) {
    $query .= "AND (cm.name LIKE ? OR cm.email LIKE ? OR cm.subject LIKE ? OR cm.message LIKE ?) ";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
    $types .= "ssss";
}

$query .= "ORDER BY cm.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$messages = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count unread messages
$unread_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM contact_messages WHERE is_read = 0");
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_count = $unread_result->fetch_assoc()['unread_count'];
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inquiries - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .message-card {
            border-left: 4px solid #0d6efd;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .message-card.unread {
            border-left-color: #ffc107;
            background-color: #fffef5;
        }
        
        .message-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .message-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .reply-section {
            background-color: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: 15px;
            margin-top: 15px;
        }
        
        .filter-badge {
            cursor: pointer;
            margin-right: 10px;
        }
        
        .search-bar {
            max-width: 400px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header d-flex align-items-center">
                <i class="fas fa-bus-alt me-2 fs-4"></i>
                <h4 class="mb-0">Admin Dashboard</h4>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../../admin.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="bookings_admin.php">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="routes_admin.php">
                        <i class="fas fa-route"></i>
                        <span>Routes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="schedules_admin.php">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="buses_admin.php">
                        <i class="fas fa-bus"></i>
                        <span>Buses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users_admin.php">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="payments_admin.php">
                        <i class="fas fa-money-check-alt"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="inquiries.php">
                        <i class="fas fa-envelope"></i>
                        <span>Inquiries</span>
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-warning ms-2"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Page Content -->
        <div class="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg navbar-light mb-4">
                <div class="container-fluid">
                    <button id="sidebarToggle" class="btn">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-envelope"></i> Customer Inquiries</h2> 
                </div>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <form class="search-bar me-3" method="GET" action="">
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" placeholder="Search messages..." value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="mb-4">
                    <a href="?filter=all" class="badge <?php echo $filter === 'all' ? 'bg-primary' : 'bg-secondary'; ?> filter-badge">
                        All Messages (<?php echo count($messages); ?>)
                    </a>
                    <a href="?filter=unread" class="badge <?php echo $filter === 'unread' ? 'bg-primary' : 'bg-secondary'; ?> filter-badge">
                        Unread (<?php echo $unread_count; ?>)
                    </a>
                    <a href="?filter=read" class="badge <?php echo $filter === 'read' ? 'bg-primary' : 'bg-secondary'; ?> filter-badge">
                        Read (<?php echo count($messages) - $unread_count; ?>)
                    </a>
                </div>

                <!-- Messages List -->
                <div class="row">
                    <div class="col-12">
                        <?php if (count($messages) > 0): ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="card message-card <?php echo $message['is_read'] ? '' : 'unread'; ?>">
                                    <div class="card-body">
                                        <div class="message-header d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1">
                                                    <?php echo htmlspecialchars($message['subject']); ?>
                                                    <?php if (!$message['is_read']): ?>
                                                        <span class="badge bg-warning ms-2">New</span>
                                                    <?php endif; ?>
                                                </h5>
                                                <p class="mb-0 text-muted">
                                                    <strong>From:</strong> <?php echo htmlspecialchars($message['name']); ?> 
                                                    (<?php echo htmlspecialchars($message['email']); ?>)
                                                </p>
                                                <p class="mb-0 text-muted">
                                                    <strong>Date:</strong> <?php echo date('M d, Y h:i A', strtotime($message['created_at'])); ?>
                                                </p>
                                            </div>
                                            <div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Actions
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <?php if ($message['is_read']): ?>
                                                            <li><a class="dropdown-item" href="?mark_read=0&id=<?php echo $message['id']; ?>">
                                                                <i class="fas fa-envelope me-2"></i>Mark as Unread
                                                            </a></li>
                                                        <?php else: ?>
                                                            <li><a class="dropdown-item" href="?mark_read=1&id=<?php echo $message['id']; ?>">
                                                                <i class="fas fa-envelope-open me-2"></i>Mark as Read
                                                            </a></li>
                                                        <?php endif; ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item text-danger" href="?delete=1&id=<?php echo $message['id']; ?>" onclick="return confirm('Are you sure you want to delete this message?');">
                                                            <i class="fas fa-trash me-2"></i>Delete
                                                        </a></li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="message-content my-3">
                                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($message['message'])); ?></p>
                                        </div>
                                        
                                        <!-- Reply Section -->
                                        <div class="reply-section">
                                            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reply-<?php echo $message['id']; ?>" aria-expanded="false">
                                                <i class="fas fa-reply me-2"></i>Reply
                                            </button>
                                            
                                            <div class="collapse mt-3" id="reply-<?php echo $message['id']; ?>">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                                    <input type="hidden" name="reply_to_email" value="<?php echo htmlspecialchars($message['email']); ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label for="reply_message" class="form-label">Reply Message</label>
                                                        <textarea class="form-control" id="reply_message" name="reply_message" rows="4" required></textarea>
                                                    </div>
                                                    
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <strong>To:</strong> <?php echo htmlspecialchars($message['name']); ?> 
                                                            (<?php echo htmlspecialchars($message['email']); ?>)
                                                        </div>
                                                        <button type="submit" name="send_reply" class="btn btn-success">
                                                            <i class="fas fa-paper-plane me-2"></i>Send Reply
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>No messages found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.body.classList.toggle('collapsed-sidebar');
        });

        // Auto-refresh page every 5 minutes to check for new messages
        setInterval(function() {
            location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>