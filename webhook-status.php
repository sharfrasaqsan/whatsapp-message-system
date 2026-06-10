<?php
// webhook-status.php
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
error_log("RAW STATUS CALLBACK WEBHOOK DATA: " . $json_data);

$data = json_decode($json_data, true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

// 3. Get fields
$customData = $data['customData'] ?? $data['custom_data'] ?? $data['customFields'] ?? $data;

$local_message_id = $data['local_message_id'] ?? $customData['local_message_id'] ?? $data['localMessageId'] ?? $data['message_id'] ?? null;
$phone_number = $data['phone_number'] ?? $customData['phone_number'] ?? $data['phone'] ?? $data['contact_phone'] ?? ($data['contact']['phone'] ?? '');
$status = strtolower(trim($data['status'] ?? $customData['status'] ?? $data['delivery_status'] ?? $data['message_status'] ?? ''));

// Validate status is one of the allowed ones
$allowed_statuses = ['pending', 'sent', 'failed', 'replied', 'delivered', 'undelivered'];

if (empty($status) || !in_array($status, $allowed_statuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing or invalid fields. Status must be one of: ' . implode(', ', $allowed_statuses),
        'received_local_message_id' => $local_message_id,
        'received_status' => $status
    ]);
    exit;
}

if (empty($local_message_id) && empty($phone_number)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing local_message_id or phone_number.'
    ]);
    exit;
}

try {
    if (!empty($local_message_id)) {
        // 4. Update Database for the given message ID
        $stmt = $pdo->prepare("UPDATE whatsapp_messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $local_message_id]);
    } else {
        // Update latest outgoing message for this phone
        $stmt = $pdo->prepare("
            UPDATE whatsapp_messages 
            SET status = ? 
            WHERE phone_number = ? AND sent_message IS NOT NULL AND status IN ('sent', 'pending', 'failed') 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$status, $phone_number]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'local_message_id' => $local_message_id,
        'phone_number' => $phone_number,
        'status' => $status
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    error_log("Status Webhook DB Error: " . $e->getMessage());
}
?>
