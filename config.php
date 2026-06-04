<?php
// config.php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'whatsapp_message_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// Workflow Webhook Configuration
// The URL provided by your workflow tool (Make, n8n, Zapier, etc.) to receive outgoing messages
define('WORKFLOW_WEBHOOK_URL', 'https://your-workflow-url.com/webhook');

// Security Configuration
// A secret token used to verify incoming requests to your webhook.php
// Choose a strong random string. Ensure your workflow tool uses this same token when calling webhook.php.
define('WEBHOOK_SECRET_TOKEN', 'YOUR_STRONG_SECRET_TOKEN_HERE');
