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
$local_message_id = $data['local_message_id'] ?? null;
$phone_number = $data['phone_number'] ?? '';
$status = strtolower(trim($data['status'] ?? ''));

// Validate status is one of the allowed ones
$allowed_statuses = ['pending', 'sent', 'failed', 'replied', 'delivered', 'undelivered'];

if (empty($local_message_id) || !in_array($status, $allowed_statuses)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing or invalid fields. Status must be one of: ' . implode(', ', $allowed_statuses),
        'received_local_message_id' => $local_message_id,
        'received_status' => $status
    ]);
    exit;
}

try {
    // 4. Update Database for the given message ID
    $stmt = $pdo->prepare("UPDATE whatsapp_messages SET status = ? WHERE id = ?");
    $stmt->execute([$status, $local_message_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'local_message_id' => $local_message_id,
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
