<?php
// index.php
session_start();
require_once 'db.php';
require_once 'functions.php';

$message = '';
$message_type = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $phone_number = trim($_POST['phone_number'] ?? '');
    $msg_text = trim($_POST['message'] ?? '');
    
    if (!validate_csrf_token($csrf_token)) {
        $message = "Invalid CSRF token. Please try again.";
        $message_type = "error";
    } elseif (empty($phone_number) || empty($msg_text)) {
        $message = "Phone number and message are required.";
        $message_type = "error";
    } else {
        try {
            // 1. Save to Database as Pending
            $stmt = $pdo->prepare("INSERT INTO whatsapp_messages (phone_number, sent_message) VALUES (?, ?)");
            $stmt->execute([$phone_number, $msg_text]);
            $local_message_id = $pdo->lastInsertId();
            
            // Get the created_at timestamp
            $stmt_dt = $pdo->prepare("SELECT created_at FROM whatsapp_messages WHERE id = ?");
            $stmt_dt->execute([$local_message_id]);
            $created_at = $stmt_dt->fetchColumn();

            // 2. Prepare Workflow Payload
            $payload = [
                'phone_number' => $phone_number,
                'message' => $msg_text,
                'local_message_id' => $local_message_id,
                'created_at' => $created_at
            ];
            
            // 3. Send to Workflow Webhook
            $workflow_result = send_to_workflow($payload);
            
            // 4. Update Database based on workflow response
            $status = $workflow_result['success'] ? 'sent' : 'failed';
            $response_text = json_encode($workflow_result); // Save raw response for debugging
            
            $update_stmt = $pdo->prepare("UPDATE whatsapp_messages SET status = ?, workflow_response = ? WHERE id = ?");
            $update_stmt->execute([$status, $response_text, $local_message_id]);
            
            if ($workflow_result['success']) {
                $message = "Message sent successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to send message to workflow. Error: " . h($workflow_result['error']);
                $message_type = "error";
            }
            
        } catch (PDOException $e) {
            $message = "Database error occurred while saving.";
            $message_type = "error";
            error_log("DB Error on send: " . $e->getMessage());
        }
    }
}

// Generate new CSRF token for the form
$csrf_token = generate_csrf_token();

// Fetch Message History
$history = [];
try {
    $stmt = $pdo->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 100");
    $history = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("DB Error fetching history: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Admin Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">
    <header>
        <h1>WhatsApp Messaging Panel</h1>
    </header>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo h($message_type); ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Send New Message</h2>
        <form method="POST" action="index.php" id="messageForm">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
            
            <div class="form-group">
                <label for="phone_number">Client Phone Number (Intl Format, e.g. +94751230001)</label>
                <input type="text" id="phone_number" name="phone_number" class="form-control" required placeholder="+94751230001">
            </div>
            
            <div class="form-group">
                <label for="message">Message Text</label>
                <textarea id="message" name="message" class="form-control" rows="4" required placeholder="Type your message here..."></textarea>
            </div>
            
            <button type="submit" id="submitBtn" class="btn">Send WhatsApp Message</button>
        </form>
    </div>

    <div class="card">
        <h2>Message History</h2>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Phone Number</th>
                        <th>Sent Message</th>
                        <th>Reply Message</th>
                        <th>Status</th>
                        <th>Sent Time</th>
                        <th>Reply Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No messages found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?php echo h($row['id']); ?></td>
                                <td><?php echo h($row['phone_number']); ?></td>
                                <td><?php echo nl2br(h($row['sent_message'])); ?></td>
                                <td><?php echo $row['reply_message'] ? nl2br(h($row['reply_message'])) : '-'; ?></td>
                                <td>
                                    <span class="badge badge-<?php echo h($row['status']); ?>">
                                        <?php echo h($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo h($row['sent_at']); ?></td>
                                <td><?php echo $row['reply_received_at'] ? h($row['reply_received_at']) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/script.js"></script>
</body>
</html>
