<?php
// db_fix_games.php
session_start();
require 'db_config.php';
if (!isset($_SESSION['user_id'])) die("AUTH REQUIRED");

echo "<pre>Installing Games System...\n";

try {
    // 1. Games Table
    // Stores the board state as a JSON blob for simplicity
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INT AUTO_INCREMENT PRIMARY KEY,
        p1_id INT NOT NULL,
        p2_id INT DEFAULT NULL,
        p1_name VARCHAR(50),
        p2_name VARCHAR(50),
        current_turn INT DEFAULT 1, -- 1 or 2
        grid_size INT DEFAULT 3,
        status ENUM('waiting', 'active', 'finished') DEFAULT 'waiting',
        board_state JSON DEFAULT NULL,
        winner INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_move DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "[OK] Table 'games' ready.\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage();
}
echo "Done.</pre>";
?>