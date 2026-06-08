-- Database name: whatsapp_message_system

CREATE DATABASE IF NOT EXISTS `whatsapp_message_system`;
USE `whatsapp_message_system`;

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `phone_number` VARCHAR(30) NOT NULL,
    `sent_message` TEXT NULL,
    `reply_message` TEXT NULL,
    `status` ENUM('pending','sent','failed','replied') DEFAULT 'pending',
    `workflow_response` TEXT NULL,
    `sent_at` DATETIME NULL DEFAULT NULL,
    `reply_received_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
