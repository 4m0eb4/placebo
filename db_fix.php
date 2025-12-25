<?php
require 'db_config.php';
echo "<body style='background:#000; color:#ccc; font-family:monospace;'>";
echo "initiating_schema_patch_v2...<br>";

try {
    // 1. Add Password & Custom Style Columns to chat_channels
    $cols = [
        "ADD COLUMN password VARCHAR(255) DEFAULT NULL",
        "ADD COLUMN pin_custom_color VARCHAR(20) DEFAULT NULL",
        "ADD COLUMN pin_custom_emoji VARCHAR(20) DEFAULT NULL"
    ];

    foreach ($cols as $sql) {
        try {
            $pdo->exec("ALTER TABLE chat_channels $sql");
            echo "[OK] $sql<br>";
        } catch (Exception $e) {
            echo "[SKIP] Column likely exists.<br>";
        }
    }
    
    // 2. Insert Default Upload Rank Setting
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('upload_min_rank', '5')");
    $stmt->execute();
    echo "[OK] upload_min_rank setting initialized.<br>";
    
    echo "<br><strong style='color:#6a9c6a;'>PATCH COMPLETE.</strong> <a href='index.php'>RETURN</a>";

} catch (Exception $e) {
    die("ERROR: " . $e->getMessage());
}
?>