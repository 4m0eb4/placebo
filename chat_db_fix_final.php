<?php
require 'db_config.php';
echo "<body style='background:#000;color:#6a9c6a;font-family:monospace;padding:20px;'>";
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("DROP TABLE IF EXISTS chat_messages");
    $pdo->exec("DROP TABLE IF EXISTS chat_signals");
    
    $pdo->exec("CREATE TABLE chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        rank INT DEFAULT 1,
        color_hex VARCHAR(7) DEFAULT '#888888',
        msg_type VARCHAR(20) DEFAULT 'normal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_at)
    ) AUTO_INCREMENT = 1");

    $pdo->exec("CREATE TABLE chat_signals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        signal_type VARCHAR(20),
        signal_val VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) AUTO_INCREMENT = 1");

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "[SUCCESS] Chat Engine Rebuilt. Auto-increment reset to 1.<br><a href='chat.php' style='color:#fff;'>Return to Chat</a>";
} catch (PDOException $e) {
    echo "[ERROR] " . htmlspecialchars($e->getMessage());
}
echo "</body>";