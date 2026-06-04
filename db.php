<?php
// db.php
require_once 'config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays by default
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In a production environment, you should log this error rather than displaying it.
    // For a beginner-friendly setup, we display a generic message.
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database connection failed. Please check your configuration.");
}
