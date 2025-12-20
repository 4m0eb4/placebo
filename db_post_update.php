<?php
// db_post_update.php - RUN ONCE
require 'db_config.php';
$msg = "";

try {
    // Add 'min_rank' to posts table
    try {
        $pdo->exec("ALTER TABLE posts ADD COLUMN min_rank INT DEFAULT 1 AFTER user_id");
        $msg .= "Added 'min_rank' column. ";
    } catch (Exception $e) {
        $msg .= "Column 'min_rank' likely exists. ";
    }

    $msg .= "Database Ready.";

} catch (PDOException $e) {
    $msg = "Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html><html><head><title>Update</title><link rel="stylesheet" href="style.css"></head><body>
<div class="login-wrapper"><div class="terminal-header"><span class="term-title">DB_UPDATE</span></div>
<div style="padding:20px;text-align:center;color:#6a9c6a;"><?= $msg ?></div></div></body></html>