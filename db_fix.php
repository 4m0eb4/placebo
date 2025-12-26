<?php
// db_whisper_patch.php
require 'db_config.php';

try {
    echo "Applying Whisper Columns Patch...<br>";
    
    // Add target_id if missing
    try {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN target_id INT DEFAULT 0");
        echo "[OK] Added 'target_id' column.<br>";
    } catch (Exception $e) { echo "[SKIP] 'target_id' likely exists.<br>"; }

    // Add target_type if missing
    try {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN target_type VARCHAR(10) DEFAULT NULL");
        echo "[OK] Added 'target_type' column.<br>";
    } catch (Exception $e) { echo "[SKIP] 'target_type' likely exists.<br>"; }

    echo "<strong>Database Patch Complete. Delete this file.</strong>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>