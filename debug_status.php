<?php
require 'db_config.php';
echo "<body style='background:#000; color:#0f0; font-family:monospace; padding:20px;'>";
echo "<h2>DIAGNOSTIC TOOL</h2>";

// 1. Check Columns
echo "Checking Columns...<br>";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'is_pinned'");
    echo "is_pinned: " . ($stmt->fetch() ? "✅ EXISTS" : "❌ MISSING") . "<br>";
    
    $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'pin_weight'");
    echo "pin_weight: " . ($stmt->fetch() ? "✅ EXISTS" : "❌ MISSING") . "<br>";
} catch(Exception $e) { echo "DB ERROR: " . $e->getMessage(); }

// 2. Check Posts
echo "<br>Checking Posts...<br>";
$posts = $pdo->query("SELECT id, title, length(body) as len FROM posts ORDER BY id DESC LIMIT 5")->fetchAll();
if(count($posts) > 0) {
    foreach($posts as $p) {
        echo "ID: {$p['id']} | Title: " . htmlspecialchars($p['title']) . " | Size: {$p['len']} bytes<br>";
    }
} else {
    echo "NO POSTS FOUND IN DATABASE.";
}
echo "</body>";
?>