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
    // Find if this contact already exists in the database under a slightly different format (e.g., with/without '+')
    $target_phone = $phone_number;
    $stmt_phone = $pdo->query("SELECT DISTINCT phone_number FROM whatsapp_messages");
    $all_phones = $stmt_phone->fetchAll(PDO::FETCH_COLUMN);
    foreach ($all_phones as $existing_phone) {
        if (normalize_phone($existing_phone) === $normalized_incoming_phone) {
            $target_phone = $existing_phone;
            break;
        }
    }

    // Insert every incoming WhatsApp reply as a NEW chat row using matching phone format
    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_messages 
        (phone_number, sent_message, reply_message, status, reply_received_at)
        VALUES (?, NULL, ?, 'replied', ?)
    ");
    $stmt->execute([$target_phone, $reply_message, $received_at]);

    $new_reply_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Reply saved successfully',
        'new_reply_id' => $new_reply_id,
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