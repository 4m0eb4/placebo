<?php
// db_rank_tool.php - RESTORED
require 'db_config.php';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'];
    $rank = (int)$_POST['rank'];
    
    $stmt = $pdo->prepare("UPDATE users SET rank = ? WHERE username = ?");
    $stmt->execute([$rank, $user]);
    
    if($stmt->rowCount() > 0) {
        $msg = "SUCCESS: $user is now Rank $rank.";
    } else {
        $msg = "ERROR: User not found.";
    }
}
?>
<!DOCTYPE html>
<html><head><title>Rank Tool</title><link rel="stylesheet" href="style.css"></head><body>
<div class="login-wrapper">
    <div class="terminal-header"><span class="term-title">Rank Tool</span></div>
    <form method="POST" style="padding: 20px;">
        <?php if($msg) echo "<div class='success'>$msg</div>"; ?>
        <div class="input-group">
            <label>Target Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="input-group">
            <label>New Rank (1-10)</label>
            <input type="number" name="rank" min="1" max="10" value="10" required>
            <small style="color:#666;">10=Owner, 9=Admin, 1=User</small>
        </div>
        <button type="submit" class="btn-primary">Update Rank</button>
    </form>
</div></body></html>