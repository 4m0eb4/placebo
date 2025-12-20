<?php
require 'db_config.php';
echo "<body style='background:#000; color:#6a9c6a; font-family:monospace; padding:40px;'>";

try {
    echo ">> PATCHING SETTINGS TABLE...<br>";
    // Insert Defaults if missing
    $defaults = [
        'invite_min_rank' => '5',
        'blacklist_usernames' => 'admin,root,system,mod,support'
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
        echo "Checked setting: $k<br>";
    }

    echo "<br>>> CHECKING USER TABLE HEALTH...<br>";
    // Check if last_active exists
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_active'")->fetchAll();
    if (count($cols) == 0) {
        echo "CRITICAL: 'last_active' column MISSING. Adding it now...<br>";
        $pdo->exec("ALTER TABLE users ADD COLUMN last_active DATETIME NULL");
        echo "FIXED: Column added.<br>";
    } else {
        echo "OK: 'last_active' column exists.<br>";
    }

    echo "<br>>> FORCE UPDATING YOUR PRESENCE...<br>";
    if (isset($_SESSION['user_id'])) {
        $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
        echo "SUCCESS: Your timestamp has been force-updated.<br>";
    } else {
        echo "NOTICE: You are not logged in. Login and refresh this page to test presence.<br>";
    }

    echo "<br><h3 style='color:#fff'>PATCH COMPLETE.</h3>";

} catch (PDOException $e) {
    echo "<h3 style='color:#e06c75'>ERROR: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
echo "</body>";
?>