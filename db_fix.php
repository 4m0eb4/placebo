<?php
// db_fix.php - Quick patch for missing columns
require 'db_config.php';

try {
    echo "<h3>Database Patcher</h3>";

    // 1. Fix 'users' table
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'color_hex'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN color_hex VARCHAR(7) DEFAULT NULL");
        echo "<div style='color:green'>[SUCCESS] Added 'color_hex' to 'users' table.</div>";
    } else {
        echo "<div style='color:orange'>[SKIP] 'color_hex' already exists in 'users'.</div>";
    }

    // 2. Clear Cache/Buffers (Helper)
    echo "<br><div>Database patch complete. Please reload your chat.</div>";

} catch (PDOException $e) {
    echo "<div style='color:red'>[ERROR] " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>