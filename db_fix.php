<?php
require 'db_config.php';

echo "<h3>INITIATING DATABASE PATCH // UPLOADS TABLE</h3>";
echo "<pre style='background:#111; color:#ccc; padding:15px;'>";

$columns = [
    'max_views' => "ALTER TABLE uploads ADD COLUMN max_views INT DEFAULT 0",
    'max_downloads' => "ALTER TABLE uploads ADD COLUMN max_downloads INT DEFAULT 0",
    'mime_type' => "ALTER TABLE uploads ADD COLUMN mime_type VARCHAR(100) DEFAULT 'application/octet-stream'",
    'title' => "ALTER TABLE uploads ADD COLUMN title VARCHAR(255) DEFAULT ''"
];

foreach ($columns as $col => $sql) {
    try {
        // Check if column exists first to avoid fatal errors on strict SQL modes
        $check = $pdo->query("SHOW COLUMNS FROM uploads LIKE '$col'");
        if ($check->fetch()) {
            echo "[SKIP] Column '$col' already exists.\n";
        } else {
            $pdo->exec($sql);
            echo "[SUCCESS] Added column '$col'.\n";
        }
    } catch (PDOException $e) {
        echo "[ERROR] Failed to add '$col': " . htmlspecialchars($e->getMessage()) . "\n";
    }
}

echo "\n------------------------------------------------\n";
echo "PATCH COMPLETE. YOU MAY NOW DELETE THIS FILE.";
echo "</pre>";
?>