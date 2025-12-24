<?php
require 'db_config.php';

try {
    // 1. Convert columns to SIGNED INT (allows negative numbers for Guests)
    // 2. Ensure they allow NULL (for empty Player 2)
    $pdo->exec("ALTER TABLE games MODIFY p1_id INT NULL");
    $pdo->exec("ALTER TABLE games MODIFY p2_id INT NULL");
    
    echo "<div style='color:green; font-family:monospace;'>SUCCESS: Games table now accepts Negative Guest IDs.</div>";
    echo "<br><a href='index.php'>Return Home</a>";

} catch (PDOException $e) {
    echo "<div style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>