<?php
require 'db_config.php';

echo "<style>body{background:#000;color:#0f0;font-family:monospace;padding:20px;}</style>";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Create PM Reactions Table (Existing)
    $sql = "CREATE TABLE IF NOT EXISTS pm_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pm_id INT NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pm_react (pm_id, user_id, emoji)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "[SUCCESS] Table 'pm_reactions' check complete.<br>";

    // 2. PATCH: Add Missing Columns for PM System
    // This fixes the 500 Error by ensuring sender_type/receiver_type exist
    $cols = $pdo->query("SHOW COLUMNS FROM private_messages")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('sender_type', $cols)) {
        $pdo->exec("ALTER TABLE private_messages ADD COLUMN sender_type ENUM('user','guest') NOT NULL DEFAULT 'user' AFTER sender_id");
        echo "[SUCCESS] Added 'sender_type' to private_messages.<br>";
    } else {
        echo "[INFO] Column 'sender_type' already exists.<br>";
    }
    
    if (!in_array('receiver_type', $cols)) {
        $pdo->exec("ALTER TABLE private_messages ADD COLUMN receiver_type ENUM('user','guest') NOT NULL DEFAULT 'user' AFTER receiver_id");
        echo "[SUCCESS] Added 'receiver_type' to private_messages.<br>";
    }

    // New Patch for Inline Whispers
    $chat_cols = $pdo->query("SHOW COLUMNS FROM chat_messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('target_id', $chat_cols)) {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN target_id INT DEFAULT NULL AFTER user_id");
        $pdo->exec("ALTER TABLE chat_messages MODIFY COLUMN msg_type ENUM('normal','system','broadcast','whisper') DEFAULT 'normal'");
        echo "[SUCCESS] Updated chat_messages for Inline Whispers.<br>";
    }
    if (!in_array('target_type', $chat_cols)) {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN target_type ENUM('user','guest') DEFAULT 'user' AFTER target_id");
        echo "[SUCCESS] Added target_type to chat_messages.<br>";
    }

} catch (PDOException $e) {
    echo "[ERROR] " . htmlspecialchars($e->getMessage());
}
?>