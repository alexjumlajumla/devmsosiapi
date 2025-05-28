<?php
/**
 * Direct table creation script for AI assistant logs
 * Run with: php create-ai-logs-table.php
 */

// Database connection parameters
$host = 'localhost';   // Usually localhost
$database = 'msosi_jumla'; // Your database name
$username = 'root';    // Database username
$password = '';        // Database password
$charset = 'utf8mb4';

// Connect to database
try {
    $dsn = "mysql:host=$host;dbname=$database;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $username, $password, $options);
    
    echo "Connected successfully to database: $database\n";
    
    // Check if table already exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'a_i_assistant_logs'");
    if ($stmt->rowCount() > 0) {
        echo "Table 'a_i_assistant_logs' already exists.\n";
    } else {
        // Create table
        $sql = "CREATE TABLE `a_i_assistant_logs` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
            `request_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `input` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `output` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `request_content` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `response_content` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `successful` tinyint(1) NOT NULL DEFAULT 0,
            `processing_time_ms` int(11) DEFAULT NULL,
            `filters_detected` json DEFAULT NULL,
            `product_ids` json DEFAULT NULL,
            `metadata` json DEFAULT NULL,
            `is_feedback_provided` tinyint(1) NOT NULL DEFAULT 0,
            `was_helpful` tinyint(1) DEFAULT NULL,
            `feedback_comment` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT NULL,
            `updated_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $pdo->exec($sql);
        echo "Table 'a_i_assistant_logs' created successfully.\n";
    }

    echo "Done.\n";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    exit(1);
} 