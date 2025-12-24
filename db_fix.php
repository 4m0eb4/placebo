<?php
require 'db_config.php';

echo "<h2>Database Repair: Guest System</h2>";

try {
    // 1. Add guest_username if missing
    try {
        $pdo->query("SELECT guest_username FROM guest_tokens LIMIT 1");
        echo "✅ Column 'guest_username' already exists.<br>";
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guest_tokens ADD COLUMN guest_username VARCHAR(50) DEFAULT NULL");
        echo "🛠️ Added column 'guest_username'.<br>";
    }

    // 2. Add guest_color if missing
    try {
        $pdo->query("SELECT guest_color FROM guest_tokens LIMIT 1");
        echo "✅ Column 'guest_color' already exists.<br>";
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guest_tokens ADD COLUMN guest_color VARCHAR(20) DEFAULT '#888888'");
        echo "🛠️ Added column 'guest_color'.<br>";
    }
    
    echo "<hr><strong>SUCCESS:</strong> Database is ready for Guest Comments.";
    
} catch (PDOException $e) {
    die("ERROR: " . $e->getMessage());
}
?>