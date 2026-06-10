<?php
// index.php
session_start();
require_once 'db.php';
require_once 'functions.php';

// Polling Handler (Real-time updates)
if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    header('Content-Type: application/json');
    
    // 1. Fetch Contact List
    $contacts = [];
    try {
        $stmt = $pdo->query("
            SELECT m1.*
            FROM whatsapp_messages m1
            INNER JOIN (
                SELECT phone_number, MAX(id) as max_id
                FROM whatsapp_messages
                GROUP BY phone_number
            ) m2 ON m1.id = m2.max_id
            ORDER BY m1.id DESC
        ");
        $contacts = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("DB Error fetching contacts in poll: " . $e->getMessage());
    }
    
    // 2. Fetch Conversation for active contact
    $conversation = [];
    $poll_phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
    if (!empty($poll_phone) && $poll_phone !== 'new') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE phone_number = ? ORDER BY id ASC");
            $stmt->execute([$poll_phone]);
            $conversation = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("DB Error fetching conversation in poll: " . $e->getMessage());
        }
    }
    
    // Format timestamps
    foreach ($contacts as &$contact) {
        $last_time = !empty($contact['reply_message']) ? $contact['reply_received_at'] : $contact['created_at'];
        $time_formatted = '';
        if (!empty($last_time)) {
            $dt = new DateTime($last_time);
            $now = new DateTime();
            if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
                $time_formatted = $dt->format('H:i');
            } else {
                $time_formatted = $dt->format('d/m/Y');
            }
        }
        $contact['time_formatted'] = $time_formatted;
    }
    
    echo json_encode([
        'contacts' => $contacts,
        'conversation' => $conversation
    ]);
    exit;
}

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
    
    // Check if request is AJAX
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => ($message_type === 'success'),
            'message' => $message,
            'message_type' => $message_type,
            'phone_number' => $phone_number ?? '',
            'sent_at' => isset($created_at) ? (new DateTime($created_at))->format('H:i') : date('H:i'),
            'status' => $status ?? 'failed'
        ]);
        exit;
    }
}

// Generate new CSRF token for the form
$csrf_token = generate_csrf_token();

// Fetch Contact List
$contacts = [];
try {
    $stmt = $pdo->query("
        SELECT m1.*
        FROM whatsapp_messages m1
        INNER JOIN (
            SELECT phone_number, MAX(id) as max_id
            FROM whatsapp_messages
            GROUP BY phone_number
        ) m2 ON m1.id = m2.max_id
        ORDER BY m1.id DESC
    ");
    $contacts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("DB Error fetching contacts: " . $e->getMessage());
}

// Determine selected contact
$selected_phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';

// If a POST request was made, keep the active chat focused on the recipient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($phone_number)) {
    $selected_phone = $phone_number;
}

// Do not auto-select latest contact anymore. Keep it unselected by default.

// Fetch Conversation for selected phone (skip if selected_phone is 'new')
$conversation = [];
if (!empty($selected_phone) && $selected_phone !== 'new') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE phone_number = ? ORDER BY id ASC");
        $stmt->execute([$selected_phone]);
        $conversation = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("DB Error fetching conversation: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Admin Panel</title>
    <link rel="stylesheet" href="assets/style.css?v=1.0.2">
</head>
<body>

<div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <div class="avatar-container">
                <span class="app-title">WhatsApp Admin</span>
            </div>
            <a href="index.php?phone=new" class="new-chat-btn" title="New Chat">
                <svg viewBox="0 0 24 24" width="24" height="24" class="icon">
                    <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                </svg>
            </a>
        </div>
        
        <!-- Search Box -->
        <div class="search-container">
            <div class="search-wrapper">
                <svg viewBox="0 0 24 24" width="18" height="18" class="search-icon">
                    <path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                </svg>
                <input type="text" id="contactSearch" placeholder="Search or start new chat" autocomplete="off">
            </div>
        </div>

        <!-- Contact List -->
        <div class="contact-list" id="contactList">
            <?php if (empty($contacts)): ?>
                <div class="no-contacts">No conversations yet</div>
            <?php else: ?>
                <?php foreach ($contacts as $contact): ?>
                    <?php 
                        $phone = $contact['phone_number'];
                        $is_active = ($phone === $selected_phone);
                        
                        // Last message preview and time
                        $last_preview = '';
                        $last_time = '';
                        
                        if (!empty($contact['reply_message'])) {
                            $last_preview = $contact['reply_message'];
                            $last_time = $contact['reply_received_at'];
                        } else {
                            $last_preview = $contact['sent_message'];
                            $last_time = $contact['created_at'];
                        }
                        
                        // Format last activity time
                        $time_formatted = '';
                        if (!empty($last_time)) {
                            $dt = new DateTime($last_time);
                            $now = new DateTime();
                            if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
                                $time_formatted = $dt->format('H:i');
                            } else {
                                $time_formatted = $dt->format('d/m/Y');
                            }
                        }
                    ?>
                    <a href="index.php?phone=<?php echo urlencode($phone); ?>" class="contact-item <?php echo $is_active ? 'active' : ''; ?>" data-phone="<?php echo h($phone); ?>">
                        <div class="contact-avatar">
                            <?php echo h(substr($phone, -2)); ?>
                        </div>
                        <div class="contact-info">
                            <div class="contact-meta">
                                <span class="contact-phone"><?php echo h($phone); ?></span>
                                <span class="contact-time"><?php echo h($time_formatted); ?></span>
                            </div>
                            <div class="contact-preview-row">
                                <span class="contact-preview"><?php echo h(mb_strimwidth($last_preview, 0, 40, '...')); ?></span>
                                <span class="status-dot status-dot-<?php echo h($contact['status']); ?>" title="<?php echo h($contact['status']); ?>"></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Right Chat Panel -->
    <main class="chat-panel <?php echo empty($selected_phone) ? 'no-chat-selected' : ''; ?>" id="chatPanel">
        <?php if (empty($selected_phone)): ?>
            <div class="chat-placeholder">
                <div class="placeholder-icon">💬</div>
                <h3>Select a contact to start messaging</h3>
                <p>Choose a contact from the sidebar or start a new chat.</p>
            </div>
        <?php else: ?>
            <!-- Chat Header -->
            <div class="chat-header">
                <button class="back-btn" id="backBtn" title="Back to Contacts">
                    <svg viewBox="0 0 24 24" width="24" height="24">
                        <path fill="currentColor" d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                    </svg>
                </button>
                <div class="chat-contact-avatar">
                    <?php echo $selected_phone === 'new' ? '?' : h(substr($selected_phone, -2)); ?>
                </div>
                <div class="chat-contact-details">
                    <h2 class="chat-contact-name"><?php echo $selected_phone === 'new' ? 'New Conversation' : h($selected_phone); ?></h2>
                    <span class="chat-contact-status">
                        <?php echo $selected_phone === 'new' ? 'Enter a number below to start' : 'Active conversation'; ?>
                    </span>
                </div>
            </div>

            <!-- Alerts inside Chat -->
            <?php if (!empty($message)): ?>
                <div class="chat-alert alert alert-<?php echo h($message_type); ?>">
                    <?php echo h($message); ?>
                </div>
            <?php endif; ?>

            <!-- Message Area -->
            <div class="message-area" id="messageArea">
                <?php if ($selected_phone === 'new' || empty($conversation)): ?>
                    <div class="conversation-empty">
                        <p>No messages yet. Send a message to start the conversation.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversation as $msg): ?>
                        <?php if (!empty($msg['sent_message'])): ?>
                            <div class="message-row outgoing">
                                <div class="message-bubble">
                                    <div class="message-text"><?php echo nl2br(h($msg['sent_message'])); ?></div>
                                    <div class="message-footer">
                                        <span class="message-time">
                                            <?php 
                                                if (!empty($msg['created_at'])) {
                                                    echo h((new DateTime($msg['created_at']))->format('H:i'));
                                                }
                                            ?>
                                        </span>
                                        <span class="status-tick status-tick-<?php echo h($msg['status']); ?>" title="<?php echo h($msg['status']); ?>">
                                            <?php if ($msg['status'] === 'failed' || $msg['status'] === 'undelivered'): ?>
                                                ⚠️ <?php if ($msg['status'] === 'undelivered') echo '<span class="undelivered-text">Not delivered</span>'; ?>
                                            <?php elseif ($msg['status'] === 'sent' || $msg['status'] === 'replied' || $msg['status'] === 'delivered'): ?>
                                                ✓✓
                                            <?php else: ?>
                                                ✓
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($msg['reply_message'])): ?>
                            <div class="message-row incoming">
                                <div class="message-bubble">
                                    <div class="message-text"><?php echo nl2br(h($msg['reply_message'])); ?></div>
                                    <div class="message-footer">
                                        <span class="message-time">
                                            <?php 
                                                if (!empty($msg['reply_received_at'])) {
                                                    echo h((new DateTime($msg['reply_received_at']))->format('H:i'));
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Composer (Form) -->
            <div class="composer">
                <form method="POST" action="index.php?phone=<?php echo urlencode($selected_phone); ?>" id="messageForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                    
                    <?php if ($selected_phone === 'new'): ?>
                        <div class="composer-phone-input">
                            <input type="text" id="phone_number" name="phone_number" class="form-control" required placeholder="Phone number (e.g. +94751230001)" value="">
                        </div>
                    <?php else: ?>
                        <input type="hidden" id="phone_number" name="phone_number" value="<?php echo h($selected_phone); ?>">
                    <?php endif; ?>

                    <div class="composer-input-row">
                        <textarea id="message" name="message" class="form-control" rows="1" required placeholder="Type a message"></textarea>
                        <button type="submit" id="submitBtn" class="send-btn" title="Send Message">
                            <svg viewBox="0 0 24 24" width="24" height="24">
                                <path fill="currentColor" d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="assets/script.js?v=1.0.3"></script>
</body>
</html>
