<?php
session_start();
require 'db_config.php';

echo "<pre style='background:#111; color:#ccc; padding:20px; font-family:monospace;'>";
echo "<h1>DIAGNOSTIC MODE // USERS ONLINE</h1>";

$my_id = $_SESSION['user_id'] ?? 'GUEST/NONE';
$my_rank = $_SESSION['rank'] ?? 0;

echo "MY ID:   $my_id\n";
echo "MY RANK: $my_rank\n";
echo "SERVER TIME (PHP): " . date('Y-m-d H:i:s') . "\n";

// 1. Check DB Time
$db_time = $pdo->query("SELECT NOW()")->fetchColumn();
echo "SERVER TIME (SQL): $db_time \n";
echo "------------------------------------------------\n";

// 2. Dump User Table (Sensitive Data Redacted)
echo "RAW USER TABLE DUMP (Last 5 Active):\n";
$stmt = $pdo->query("SELECT id, username, show_online, last_active, NOW() as current_db_time, TIMEDIFF(NOW(), last_active) as diff FROM users ORDER BY last_active DESC LIMIT 5");
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "!! TABLE IS EMPTY !!\n";
} else {
    printf("%-5s %-15s %-10s %-20s %-15s\n", "ID", "USER", "SHOW?", "LAST ACTIVE", "AGE (H:M:S)");
    foreach($rows as $r) {
        printf("%-5s %-15s %-10s %-20s %-15s\n", 
            $r['id'], 
            $r['username'], 
            $r['show_online'], 
            $r['last_active'] ?? 'NULL', 
            $r['diff'] ?? 'N/A'
        );
    }
}

echo "------------------------------------------------\n";
echo "If 'AGE' is > 00:05:00, they are considered OFFLINE.\n";
echo "If 'LAST ACTIVE' is NULL, run db_feature_patch.php.\n";
echo "</pre>";
?>