<?php
// db_comment_update.php
session_start();
require 'db_config.php';

if (!isset($_SESSION['rank']) || $_SESSION['rank'] < 10) die("ACCESS DENIED");

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            parent_id INT DEFAULT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "SUCCESS: 'post_comments' table created.";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
?>