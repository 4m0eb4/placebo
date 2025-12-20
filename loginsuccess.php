<?php
session_start();
require 'db_config.php';
require 'bbcode.php'; 

if (!isset($_SESSION['fully_authenticated']) || $_SESSION['fully_authenticated'] !== true) {
    header("Location: login.php"); exit;
}

// Fetch Dynamic Login Message
$login_msg = "";
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'login_message'");
    $stmt->execute();
    if($row = $stmt->fetch()) $login_msg = $row['setting_value'];
} catch (Exception $e) {}

// Default Fallback if empty
if (!$login_msg) {
    $login_msg = "[h2]Latest updates[/h2]\n[list]\n[*]Nothing just yet...\n[*]-\n[*]-\n[/list]";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Success</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper" style="width: 500px;">
    <div class="terminal-header">
        <span class="term-title">Success</span>
        <span class="status-ok"> Last Update 19/12/25</span>
    </div>
    <div style="padding: 25px;">
        <h2 style="color: #6a9c6a; margin-top: 0; font-size: 1.2rem;">WELCOME, <?= htmlspecialchars($_SESSION['username']) ?>.</h2>
        
        <div class="rules-box" style="margin: 20px 0; border: 1px dashed #333; padding: 15px;">
            <?= parse_bbcode($login_msg) ?>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php" class="btn-primary" style="display:inline-block; width:auto; padding: 12px 30px; text-decoration:none;">Enter</a>
        </div>
    </div>
</div>
</body>
</html>