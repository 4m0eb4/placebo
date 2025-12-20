<?php
// db_master_update.php - RUN THIS TO FIX 500 ERRORS
require 'db_config.php';
echo "<body style='background:#0d0d0d;color:#6a9c6a;font-family:monospace;padding:20px;'>";

try {
    // 1. Create/Update Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, pgp_public_key TEXT NOT NULL, pgp_fingerprint VARCHAR(100) NOT NULL, rank INT DEFAULT 1, chat_color VARCHAR(7) DEFAULT '#888888', show_online TINYINT(1) DEFAULT 1, user_status VARCHAR(255), is_banned TINYINT(1) DEFAULT 0, force_logout TINYINT(1) DEFAULT 0, warning_count INT DEFAULT 0, last_active TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS guest_tokens (id INT AUTO_INCREMENT PRIMARY KEY, token VARCHAR(255) NOT NULL, created_by INT NOT NULL, guest_username VARCHAR(100), guest_session_id VARCHAR(255), status ENUM('pending', 'active', 'revoked') DEFAULT 'pending', expires_at DATETIME, last_active TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, username VARCHAR(50) NOT NULL, message TEXT NOT NULL, rank INT DEFAULT 1, color_hex VARCHAR(7) DEFAULT '#888888', msg_type VARCHAR(20) DEFAULT 'normal', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (created_at))");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, min_rank INT DEFAULT 1, preview_cutoff INT DEFAULT 250, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(50), action VARCHAR(255), ip_addr VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_signals (id INT AUTO_INCREMENT PRIMARY KEY, signal_type VARCHAR(20), signal_val VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reactions (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT, user_id INT, emoji VARCHAR(10), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS shared_links (id INT AUTO_INCREMENT PRIMARY KEY, url TEXT, title VARCHAR(255), posted_by VARCHAR(50), status ENUM('pending', 'approved', 'banned') DEFAULT 'pending', original_message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS banned_patterns (id INT AUTO_INCREMENT PRIMARY KEY, pattern VARCHAR(255), reason VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_messages (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT, receiver_id INT, message TEXT, is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    // 2. Inject Missing Columns (Silent catch if they exist)
    $alters = [
        "ALTER TABLE users ADD COLUMN last_active TIMESTAMP NULL",
        "ALTER TABLE users ADD COLUMN chat_color VARCHAR(7) DEFAULT '#888888'",
        "ALTER TABLE users ADD COLUMN force_logout TINYINT(1) DEFAULT 0",
        "ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0",
        "ALTER TABLE guest_tokens ADD COLUMN last_active TIMESTAMP NULL",
        "ALTER TABLE guest_tokens ADD COLUMN expires_at DATETIME",
        "ALTER TABLE posts ADD COLUMN min_rank INT DEFAULT 1"
    ];

    foreach($alters as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
    
    echo "[SUCCESS] MASTER SCHEMA REPAIRED. You can now use the site.";

} catch (PDOException $e) {
    echo "[ERROR] " . htmlspecialchars($e->getMessage());
}
echo "</body>";
?>