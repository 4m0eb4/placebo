<?php
require 'db_config.php';

try {
    // 1. PENALTY REASONS (Users Table)
    $pdo->exec("ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN mute_reason VARCHAR(255) NULL");
    echo "[OK] Penalty columns added.<br>";

    // 2. UPLOAD AUTO-DELETE (Uploads Table)
    // views = current views, max_views = limit (0 = unlimited)
    $pdo->exec("ALTER TABLE uploads ADD COLUMN views INT DEFAULT 0");
    $pdo->exec("ALTER TABLE uploads ADD COLUMN max_views INT DEFAULT 0");
    $pdo->exec("ALTER TABLE uploads ADD COLUMN downloads INT DEFAULT 0");
    $pdo->exec("ALTER TABLE uploads ADD COLUMN max_downloads INT DEFAULT 0");
    echo "[OK] Upload auto-delete columns added.<br>";

    // 3. FIX POST VIEWS (Ensure default is 0, not NULL)
    $pdo->exec("ALTER TABLE posts MODIFY COLUMN views INT DEFAULT 0");
    // Fix existing NULLs
    $pdo->exec("UPDATE posts SET views = 0 WHERE views IS NULL");
    echo "[OK] Post views fixed (NULL -> 0).<br>";

    // 4. WARNINGS SYSTEM (New Table)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_warnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        staff_id INT NOT NULL,
        reason VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "[OK] Warnings table created.<br>";

    echo "<b>PATCH COMPLETE. DELETE THIS FILE.</b>";

} catch (Exception $e) {
    echo "Error (Ignore if columns exist): " . $e->getMessage();
}
?>