<?php
// webhook.php
require_once 'db.php';

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

// Save raw webhook data into Railway logs for checking
error_log("RAW FIXxable WEBHOOK DATA: " . $json_data);

$data = json_decode($json_data, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

// 3. Fixxable may send custom data in different places
$customData = $data['customData'] ?? $data['custom_data'] ?? $data['customFields'] ?? [];

// 4. Get phone number
$phone_number =
    $data['phone_number']
    ?? $customData['phone_number']
    ?? $data['phone']
    ?? $data['contact_phone']
    ?? ($data['contact']['phone'] ?? '')
    ?? '';

// 5. Get reply message
$reply_message =
    $data['reply_message']
    ?? $customData['reply_message']
    ?? $data['message_body']
    ?? ($data['message']['body'] ?? '')
    ?? $data['body']
    ?? $data['message']
    ?? '';

// 6. Optional fields
$local_message_id =
    $data['local_message_id']
    ?? $customData['local_message_id']
    ?? null;

$received_at = $data['received_at'] ?? date('Y-m-d H:i:s');

// 7. Clean values
$phone_number = trim((string)$phone_number);
$reply_message = trim((string)$reply_message);

// 8. Validate Required Fields
if (empty($phone_number) || empty($reply_message)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing phone_number or reply_message',
        'received_data' => $data
    ]);
    exit;
}

try {
    if (!empty($local_message_id)) {
        $stmt = $pdo->prepare("
            UPDATE whatsapp_messages 
            SET reply_message = ?, reply_received_at = ?, status = 'replied' 
            WHERE id = ? AND phone_number = ?
        ");
        $stmt->execute([$reply_message, $received_at, $local_message_id, $phone_number]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                UPDATE whatsapp_messages 
                SET reply_message = ?, reply_received_at = ?, status = 'replied' 
                WHERE phone_number = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$reply_message, $received_at, $phone_number]);
        }
    } else {
        $stmt = $pdo->prepare("
            UPDATE whatsapp_messages 
            SET reply_message = ?, reply_received_at = ?, status = 'replied' 
            WHERE phone_number = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$reply_message, $received_at, $phone_number]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reply saved successfully',
        'phone_number' => $phone_number,
        'reply_message' => $reply_message
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    error_log("Webhook DB Error: " . $e->getMessage());
}