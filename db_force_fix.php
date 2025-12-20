<?php
require 'db_config.php';
echo "<body style='background:#0d0d0d; color:#ccc; font-family:monospace; padding:30px;'>";

function add_col($pdo, $col, $def) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        echo "<span style='color:#6a9c6a'>[SUCCESS] Added column: $col</span><br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<span style='color:#d19a66'>[OK] Column $col already exists.</span><br>";
        } else {
            echo "<span style='color:#e06c75'>[ERROR] $col: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        }
    }
}

echo "<h3>>> DIAGNOSING & REPAIRING DATABASE...</h3>";

// 1. Add 'show_online' (The Visibility Toggle)
add_col($pdo, "show_online", "TINYINT(1) DEFAULT 1");

// 2. Add 'user_status' (The Custom Status Text)
add_col($pdo, "user_status", "VARCHAR(50) DEFAULT NULL");

// 3. Add 'last_active' (The Online Tracker - Just in case)
add_col($pdo, "last_active", "DATETIME NULL");

// 4. Add 'chat_color' (If missing)
add_col($pdo, "chat_color", "VARCHAR(7) DEFAULT '#888888'");

// 5. Force Update Your User to be Visible
if (isset($_SESSION['user_id'])) {
    $pdo->prepare("UPDATE users SET show_online = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
    echo "<br>>> FORCED YOUR PROFILE TO 'ONLINE'.";
}

echo "<br><br><h2 style='color:#fff'>REPAIR COMPLETE.</h2>";
echo "<a href='settings.php' style='color:#6a9c6a; font-weight:bold;'>[1] TEST SETTINGS</a> | ";
echo "<a href='users_online.php' style='color:#6a9c6a; font-weight:bold;'>[2] CHECK ONLINE LIST</a>";
echo "</body>";
?>