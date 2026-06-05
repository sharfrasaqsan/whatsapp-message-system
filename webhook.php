<?php
// webhook.php
require_once 'db.php';

header('Content-Type: application/json');

// Helper: convert phone number to only digits
function normalize_phone($phone) {
    return preg_replace('/\D+/', '', $phone);
}

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

// Save raw Fixxable data in Railway logs
error_log("RAW FIXXABLE WEBHOOK DATA: " . $json_data);

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
$normalized_incoming_phone = normalize_phone($phone_number);

// 8. Validate Required Fields
if (empty($phone_number) || empty($reply_message)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing phone_number or reply_message',
        'received_phone_number' => $phone_number,
        'received_reply_message' => $reply_message,
        'received_data' => $data
    ]);
    exit;
}

try {
    $message_id_to_update = null;

    // If local_message_id exists, try that first
    if (!empty($local_message_id)) {
        $stmt = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE id = ? LIMIT 1");
        $stmt->execute([$local_message_id]);
        $row = $stmt->fetch();

        if ($row) {
            $message_id_to_update = $row['id'];
        }
    }

    // If no local_message_id, find latest message by matching phone number safely
    if (empty($message_id_to_update)) {
        $stmt = $pdo->query("SELECT id, phone_number FROM whatsapp_messages ORDER BY id DESC LIMIT 100");
        $messages = $stmt->fetchAll();

        foreach ($messages as $msg) {
            if (normalize_phone($msg['phone_number']) === $normalized_incoming_phone) {
                $message_id_to_update = $msg['id'];
                break;
            }
        }
    }

    // If no matching message found
    if (empty($message_id_to_update)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No matching message found for this phone number',
            'received_phone_number' => $phone_number,
            'normalized_phone' => $normalized_incoming_phone
        ]);
        exit;
    }

    // Update the message
    $stmt = $pdo->prepare("
        UPDATE whatsapp_messages 
        SET reply_message = ?, reply_received_at = ?, status = 'replied' 
        WHERE id = ?
    ");
    $stmt->execute([$reply_message, $received_at, $message_id_to_update]);

    echo json_encode([
        'success' => true,
        'message' => 'Reply saved successfully',
        'updated_message_id' => $message_id_to_update,
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