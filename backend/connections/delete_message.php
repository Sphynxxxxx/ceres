<?php
// delete_message.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Database connection
require_once "../../backend/connections/config.php";

// Check if connection exists and is valid
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

// Get the JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !is_numeric($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid message ID']);
    exit;
}

$messageId = intval($data['id']);
$userId = $_SESSION['user_id'];

try {
    // First, verify that the message belongs to the user
    $checkQuery = "SELECT id FROM contact_messages WHERE id = ? AND user_id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    
    if (!$checkStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $checkStmt->bind_param("ii", $messageId, $userId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Message not found or unauthorized']);
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    // Delete any replies associated with this message first
    $deleteRepliesQuery = "DELETE FROM contact_replies WHERE message_id = ?";
    $deleteRepliesStmt = $conn->prepare($deleteRepliesQuery);
    
    if (!$deleteRepliesStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $deleteRepliesStmt->bind_param("i", $messageId);
    $deleteRepliesStmt->execute();
    
    // Now delete the message
    $deleteMessageQuery = "DELETE FROM contact_messages WHERE id = ? AND user_id = ?";
    $deleteMessageStmt = $conn->prepare($deleteMessageQuery);
    
    if (!$deleteMessageStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $deleteMessageStmt->bind_param("ii", $messageId, $userId);
    $deleteMessageStmt->execute();
    
    if ($deleteMessageStmt->affected_rows > 0) {
        // Commit transaction
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Message deleted successfully']);
    } else {
        // Rollback transaction
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to delete message']);
    }
    
    // Close statements
    $checkStmt->close();
    $deleteRepliesStmt->close();
    $deleteMessageStmt->close();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->errno) {
        $conn->rollback();
    }
    
    // Log the error
    error_log("Error deleting message: " . $e->getMessage());
    
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the message']);
}

// Close database connection
$conn->close();
?>