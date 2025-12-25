<?php
require 'db_config.php';

try {
    echo "Checking Invite Table...<br>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(64) NOT NULL UNIQUE,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_used TINYINT(1) DEFAULT 0,
        used_by INT DEFAULT NULL
    )");
    echo "SUCCESS: 'invites' table verified.<br>";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
?>