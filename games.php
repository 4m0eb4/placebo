<?php
session_start();

// Security: Guests cannot access this module
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}

require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Simulations</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper" style="width: 500px;">
    <div class="terminal-header">
        <a href="index.php" class="term-logo">Placebo</a>
        <span style="color:#333; font-family:monospace; font-weight:bold; margin-left:5px;">// Games</span>
    </div>
    
    <div style="padding: 30px; text-align: center;">
        <h2 style="color: #6a9c6a; margin-top: 0; font-size: 1rem; border-bottom: 1px dashed #333; padding-bottom: 10px; margin-bottom: 20px;">AVAILABLE MODULES</h2>
        
        <div style="display: grid; gap: 10px; text-align: left;">
            <div style="background: #111; padding: 10px; border: 1px solid #222; opacity: 0.5;">
                <div style="color: #666; font-weight: bold; font-size: 0.8rem;">BLACKJACK_V1</div>
                <div style="color: #444; font-size: 0.7rem;">Status: Offline / Maintenance</div>
            </div>

            <div style="background: #111; padding: 10px; border: 1px solid #222; opacity: 0.5;">
                <div style="color: #666; font-weight: bold; font-size: 0.8rem;">GLOBAL_THERMONUCLEAR_WAR</div>
                <div style="color: #444; font-size: 0.7rem;">Status: Awaiting Players</div>
            </div>
        </div>

        <div style="margin-top: 30px; border-top: 1px solid #222; padding-top: 15px;">
            <a href="index.php" class="link-secondary">&lt; RETURN TO MAINFRAME</a>
        </div>
    </div>
</div>
</body>
</html>