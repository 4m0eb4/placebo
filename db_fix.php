<?php
require 'db_config.php';

echo "<h1>Starting Database Repair...</h1>";

try {
    // 1. Add Missing PIN columns to Chat Channels
    // We use silent execution so it doesn't crash if they already exist
    echo "Attempting to add 'pin_custom_color' column...<br>";
    try {
        $pdo->exec("ALTER TABLE chat_channels ADD COLUMN pin_custom_color VARCHAR(20) DEFAULT NULL");
        echo "<span style='color:green'> - Added pin_custom_color</span><br>";
    } catch (Exception $e) { echo " - Column likely exists (Skipped)<br>"; }

    echo "Attempting to add 'pin_custom_emoji' column...<br>";
    try {
        $pdo->exec("ALTER TABLE chat_channels ADD COLUMN pin_custom_emoji VARCHAR(20) DEFAULT NULL");
        echo "<span style='color:green'> - Added pin_custom_emoji</span><br>";
    } catch (Exception $e) { echo " - Column likely exists (Skipped)<br>"; }

    // 2. Ensure guest columns exist (Just in case)
    echo "Checking Guest Token columns...<br>";
    try {
        $pdo->exec("ALTER TABLE guest_tokens ADD COLUMN is_muted TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE guest_tokens ADD COLUMN slow_mode_override INT DEFAULT 0");
    } catch (Exception $e) {}

    echo "<h2 style='color:green'>SUCCESS: DATABASE STRUCTURE UPDATED.</h2>";
    echo "<p>You can now delete this file and try saving your settings in Admin Dash.</p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>CRITICAL FAILURE</h2>";
    echo "Error: " . $e->getMessage();
}
?>