<?php
// webhook.php
require_once 'db.php';

// Set header to return JSON response
header('Content-Type: application/json');

// 1. Verify Secret Token
$token = $_GET['token'] ?? '';
if ($token !== WEBHOOK_SECRET_TOKEN) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized request'
    ]);
    exit;
}

// 2. Read Incoming JSON Request
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

// 3. Extract Fields
$phone_number = $data['phone_number'] ?? '';
$reply_message = $data['reply_message'] ?? '';
$local_message_id = $data['local_message_id'] ?? null;
// Use received_at from payload, otherwise current time
$received_at = $data['received_at'] ?? date('Y-m-d H:i:s');

// 4. Validate Required Fields
if (empty($phone_number) || empty($reply_message)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing phone_number or reply_message'
    ]);
    exit;
}

try {
    if (!empty($local_message_id)) {
        // Update specific message by ID
        $stmt = $pdo->prepare("
            UPDATE whatsapp_messages 
            SET reply_message = ?, reply_received_at = ?, status = 'replied' 
            WHERE id = ? AND phone_number = ?
        ");
        $stmt->execute([$reply_message, $received_at, $local_message_id, $phone_number]);
        
        // Check if any row was updated (in case ID and phone don't match)
        if ($stmt->rowCount() === 0) {
            // Fallback: try to find latest message for this phone
             $stmt = $pdo->prepare("
                UPDATE whatsapp_messages 
                SET reply_message = ?, reply_received_at = ?, status = 'replied' 
                WHERE phone_number = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$reply_message, $received_at, $phone_number]);
        }
    } else {
        // Find latest message for this phone number
        $stmt = $pdo->prepare("
            UPDATE whatsapp_messages 
            SET reply_message = ?, reply_received_at = ?, status = 'replied' 
            WHERE phone_number = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$reply_message, $received_at, $phone_number]);
    }
    
    // Return Success Response
    echo json_encode([
        'success' => true,
        'message' => 'Reply saved successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    error_log("Webhook DB Error: " . $e->getMessage());
}
