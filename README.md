# WhatsApp Message System

A simple, beginner-friendly PHP and MySQL web application to send WhatsApp messages and receive replies via webhooks, without using complex frameworks.

## 1. How to Create the Database
- Open your database management tool (e.g., phpMyAdmin, TablePlus).
- Create a new database named `whatsapp_message_system`.
- Alternatively, run this SQL command: `CREATE DATABASE whatsapp_message_system;`

## 2. How to Import database.sql
- Open `database.sql` in this folder.
- Import the file into your `whatsapp_message_system` database using phpMyAdmin (Import tab) or via command line:
  `mysql -u root -p whatsapp_message_system < database.sql`

## 3. How to Update config.php
- Open `config.php` in your text editor.
- Update `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` with your local or shared hosting database credentials.
- Set `WORKFLOW_WEBHOOK_URL` to the webhook URL provided by Make, n8n, Zapier, etc., where you want to send outgoing messages.
- Set `WEBHOOK_SECRET_TOKEN` to a secure random string (e.g., "MySuperSecretToken123"). You will use this token in your workflow tool to authenticate incoming replies to your app.

## 4. How to Run the Project Locally
- Place the `whatsapp-message-system` folder in your local web server's public directory (e.g., `htdocs` for XAMPP, `www` for Laragon).
- Open your browser and go to `http://localhost/whatsapp-message-system/index.php`.

## 5. How to Connect the Workflow Webhook URL
- In your workflow tool (e.g., Make.com, n8n), create a new scenario with a Webhook trigger.
- The tool will give you a unique URL. Copy this URL and paste it into `config.php` as the `WORKFLOW_WEBHOOK_URL`.
- Whenever you submit the form on `index.php`, your app will send a POST request to this URL.

## 6. How the Sending Process Works
1. You fill out the phone number and message on the admin panel and click "Send".
2. The app saves the message in MySQL with a status of `pending` and gets the `local_message_id`.
3. The app sends a POST request (JSON) to your configured `WORKFLOW_WEBHOOK_URL`.
4. If the workflow responds with a 2xx success code, the message status updates to `sent`. Otherwise, it updates to `failed`.

## 7. How Incoming Replies are Saved
1. When a client replies, your workflow tool catches the reply.
2. The workflow tool must then make a POST request to your `webhook.php`.
   - The URL must include the token: `http://your-domain.com/whatsapp-message-system/webhook.php?token=YOUR_SECRET_TOKEN_HERE`
3. The app checks the token, reads the JSON payload, finds the original message in the database (via `local_message_id` or phone number), updates the `reply_message` and `reply_received_at`, and changes the status to `replied`.

## 8. Example Outgoing Payload
When sending a message, your app sends this JSON to your workflow:
```json
{
  "phone_number": "+94751230001",
  "message": "Hello, this is a test WhatsApp message.",
  "local_message_id": "1",
  "created_at": "2026-06-03 10:30:00"
}
```

## 9. Example Incoming Reply Payload
Your workflow tool should send this JSON to your `webhook.php` when a reply is received:
```json
{
  "phone_number": "+94751230001",
  "reply_message": "Thank you, I received it.",
  "local_message_id": 1,
  "received_at": "2026-06-03 10:35:00"
}
```

## 10. Testing Steps
### Syntax Error Check
- Run `php -l index.php` and `php -l webhook.php` in your terminal to ensure there are no syntax errors.

### Local Testing (XAMPP/Laragon)
- Follow steps 1-4.
- Open the admin panel and try sending a message. It will likely fail the cURL request unless you set up a mock webhook (e.g., using webhook.site).
- Check the database to ensure the message was inserted correctly.

### Testing Webhook with Postman
- Open Postman and create a new POST request.
- Enter URL: `http://localhost/whatsapp-message-system/webhook.php?token=YOUR_STRONG_SECRET_TOKEN_HERE` (Make sure the token matches `config.php`).
- Go to Body -> raw -> JSON.
- Paste the "Example Incoming Reply Payload" from section 9.
- Click Send. You should receive a `{"success":true,"message":"Reply saved successfully"}` response.
- Refresh your admin panel; you should see the reply message and a green "replied" badge!
