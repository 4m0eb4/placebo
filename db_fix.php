<?php
// db_feature_patch.php - Run Once to Fix Tables
require 'db_config.php';

try {
    echo "<body style='background:#0d0d0d; color:#6a9c6a; font-family:monospace; padding:20px;'>";
    echo "<h3>INITIATING DATABASE REPAIR...</h3>";

    // 1. Fix Image Viewer Comments (Fixes 500 Error)
    $pdo->exec("CREATE TABLE IF NOT EXISTS upload_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        user_id INT NOT NULL,
        parent_id INT DEFAULT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div>[+] Table 'upload_comments' verified.</div>";

    // 2. Add Ban/Mute Reason Columns (Fixes Moderation)
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'ban_reason'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT 'Connection Terminated'");
        echo "<div>[+] Column 'ban_reason' added.</div>";
    }

    $cols2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'mute_reason'")->fetch();
    if (!$cols2) {
        $pdo->exec("ALTER TABLE users ADD COLUMN mute_reason VARCHAR(255) DEFAULT 'Conduct'");
        echo "<div>[+] Column 'mute_reason' added.</div>";
    }

    echo "<h3 style='color:#e5c07b;'>PATCH COMPLETE. DELETE THIS FILE.</h3>";
    echo "</body>";

} catch (Exception $e) {
    echo "<div style='color:#e06c75;'>CRITICAL FAIL: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>