<?php
require 'db_config.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "CREATE TABLE IF NOT EXISTS comment_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        comment_id INT NOT NULL,
        user_id INT NOT NULL,
        vote TINYINT NOT NULL, 
        UNIQUE KEY (comment_id, user_id),
        INDEX (comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "<strong style='color:green'>SUCCESS: 'comment_votes' table created.</strong>";

} catch (PDOException $e) {
    echo "ERROR: " . htmlspecialchars($e->getMessage());
}
?>