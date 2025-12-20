<?php
session_start();

if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}

require 'db_config.php'; // Required for Global Theme

if (!isset($_SESSION['fully_authenticated']) || $_SESSION['fully_authenticated'] !== true) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Dashboard</title><link rel="stylesheet" href="style.css"></head>
<body class="<?= $theme_cls ?>" <?= $bg_style ?>>
<div class="login-wrapper" style="width: 600px; text-align: left;">
        <div class="terminal-header">
            <a href="index.php" class="term-logo">Placebo</a>
            <span class="status-ok">Session Active</span>
        </div>
        <div style="padding: 25px;">
            <h2 style="color: #6a9c6a; margin-top: 0;">WELCOME, <?= htmlspecialchars($_SESSION['username']) ?></h2>
            <p>You have successfully navigated the security protocols.</p>
            <hr style="border: 0; border-bottom: 1px solid #333; margin: 20px 0;">
            
            <a href="logout.php" class="btn-primary" style="display:inline-block; width:auto; text-align:center;">TERMINATE SESSION</a>
        </div>
    </div>
</body>
</html>