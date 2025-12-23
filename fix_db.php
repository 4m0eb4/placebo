<?php
require 'db_config.php';

echo "<style>body{background:#000;color:#0f0;font-family:monospace;padding:20px;}</style>";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "CREATE TABLE IF NOT EXISTS pm_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pm_id INT NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pm_react (pm_id, user_id, emoji)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "[SUCCESS] Table 'pm_reactions' created successfully.";

} catch (PDOException $e) {
    echo "[ERROR] " . htmlspecialchars($e->getMessage());
}
?>