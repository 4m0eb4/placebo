<?php
session_start();
require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['user_id'])) die("ACCESS DENIED");
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) die("GUESTS CANNOT INVITE");

// --- DYNAMIC RANK CHECK ---
$req_rank = 5;
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'invite_min_rank'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $req_rank = (int)$val;
} catch (Exception $e) {}

if (($_SESSION['rank'] ?? 0) < $req_rank) {
    die("<html><body style='background:#000;color:#e06c75;font-family:monospace;display:flex;justify-content:center;align-items:center;height:100vh;'>
        INSUFFICIENT CLEARANCE (LEVEL $req_rank REQUIRED)
    </body></html>");
}

$invite_code = '';
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = strtoupper(bin2hex(random_bytes(4)));
        $expires = date('Y-m-d H:i:s', strtotime("+24 hours"));

        $stmt = $pdo->prepare("INSERT INTO guest_tokens (token, created_by, expires_at, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$token, $_SESSION['user_id'], $expires]);
        
        $invite_code = $token;
    } catch (Exception $e) {
        $msg = "DB Error";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: transparent; padding: 20px; display: flex; justify-content: center; font-family: monospace; }
        .invite-box {
            background: #161616; border: 1px solid #333; width: 100%; max-width: 350px; 
            padding: 15px; position: relative; box-shadow: 0 0 10px rgba(0,0,0,0.5);
        }
        .btn-primary { padding: 8px 15px; font-size: 0.75rem; width: 100%; }
    </style>
</head>
<body>
    <div class="invite-box">
        <a href="chat.php" style="position: absolute; top: 8px; right: 10px; color: #666; text-decoration: none; font-size:0.7rem;">[ CLOSE ]</a>
        
        <h2 style="color: #6a9c6a; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 5px; font-size: 0.9rem; margin-bottom: 15px;">INVITE SYSTEM</h2>
        
        <?php if($invite_code): ?>
            <div style="background: #000; padding: 10px; border: 1px dashed #6a9c6a; text-align: center; margin-bottom: 10px;">
                <div style="font-size: 0.65rem; color: #888;">ACCESS KEY GENERATED</div>
                <div style="font-size: 1.2rem; color: #fff; letter-spacing: 2px; font-family: monospace; user-select: all; margin-top:5px;"><?= $invite_code ?></div>
            </div>
            <p style="color: #aaa; font-size: 0.7rem; text-align: center; margin:0;">Valid for 24 hours.</p>
        <?php else: ?>
            <p style="color: #ccc; font-size: 0.8rem; margin-bottom: 15px; line-height:1.4;">
                Generate a one-time registration key.<br>
                <span style="color: #e06c75; font-size:0.7rem;">NOTE: You are responsible for your invites.</span>
            </p>
            <form method="POST">
                <button type="submit" name="generate" class="btn-primary">CREATE KEY</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>