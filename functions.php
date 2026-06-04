<?php
// functions.php
require_once 'db.php';

/**
 * Generate a CSRF token and store it in the session
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token
 */
function validate_csrf_token($token) {
    if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    return false;
}

/**
 * Sanitize output to prevent XSS
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Send a message to the workflow webhook
 */
function send_to_workflow($payload) {
    $ch = curl_init(WORKFLOW_WEBHOOK_URL);
    $json_payload = json_encode($payload);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_payload)
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'success' => ($http_code >= 200 && $http_code < 300),
        'response' => $response,
        'error' => $error,
        'http_code' => $http_code
    ];
}
