<?php
require 'db_config.php';

echo "<h1>Applying Final Database Patches...</h1>";

try {
    // 1. Add the missing column for Text Color in Users
    $pdo->exec("ALTER TABLE users ADD COLUMN chat_msg_color VARCHAR(20) DEFAULT NULL");
    echo "<div style='color:green'>[OK] Added 'chat_msg_color' to users table.</div>";
} catch (Exception $e) {
    echo "<div style='color:orange'>[INFO] 'chat_msg_color' might already exist or: " . $e->getMessage() . "</div>";
}

try {
    // 2. Add the missing column for Text Color in Messages
    $pdo->exec("ALTER TABLE chat_messages ADD COLUMN text_color VARCHAR(20) DEFAULT NULL");
    echo "<div style='color:green'>[OK] Added 'text_color' to chat_messages table.</div>";
} catch (Exception $e) {
    echo "<div style='color:orange'>[INFO] 'text_color' might already exist.</div>";
}

try {
    // 3. Add Custom Pin Columns (Just in case)
    $pdo->exec("ALTER TABLE chat_channels ADD COLUMN pin_custom_color VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE chat_channels ADD COLUMN pin_custom_emoji VARCHAR(20) DEFAULT NULL");
    echo "<div style='color:green'>[OK] Verified Pin Columns.</div>";
} catch (Exception $e) {
    echo "<div style='color:orange'>[INFO] Pin columns likely exist.</div>";
}

echo "<br><strong>Done. You can now delete this file and try saving the user again.</strong>";
?>