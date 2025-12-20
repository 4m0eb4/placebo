<?php
session_start();
require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['user_id'])) die("ACCESS DENIED");
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) die("GUESTS CANNOT INVITE");

// --- DYNAMIC RANK CHECK ---
// Fetch min rank from settings (Default: 5)
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

$token_display = '';
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hours = (int)($_POST['duration'] ?? 1);
    if ($hours < 1) $hours = 1;
    if ($hours > 24) $hours = 24;

    try {
        $token = strtoupper(bin2hex(random_bytes(4)));
        $expires = date('Y-m-d H:i:s', strtotime("+$hours hours"));

        // Insert into DB
        $stmt = $pdo->prepare("INSERT INTO guest_tokens (token, created_by, expires_at, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$token, $_SESSION['user_id'], $expires]);
        
        $token_display = $token;
    } catch (Exception $e) {
        $msg = "DB Error: Ensure database is updated.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { 
            display: flex !important; 
            flex-direction: column !important;
            justify-content: center !important;
            background: #161616; 
            padding: 20px; 
            text-align: center; 
            height: 100vh !important; 
            box-sizing: border-box;
            font-family: monospace;
        }
        .token-box { 
            background: #000; border: 1px dashed #6a9c6a; 
            color: #6a9c6a; padding: 15px; font-size: 1.8rem; 
            margin: 15px 0; letter-spacing: 4px; user-select: all;
        }
        input[type="number"] { 
            background: #000; color: #fff; border: 1px solid #333; 
            padding: 8px; width: 60px; text-align: center; outline: none;
        }
    </style>
</head>
<body>

    <?php if($token_display): ?>
        <div style="color:#fff; font-size:0.8rem;">TOKEN GENERATED</div>
        <div class="token-box"><?= $token_display ?></div>
        <p style="font-size: 0.75rem; color: #666; margin-bottom:15px;">Valid for <?= $hours ?> Hours.</p>
        
        <form method="POST">
            <button type="submit" class="btn-primary" style="width: auto; padding: 10px 20px;">GENERATE NEW</button>
        </form>

    <?php else: ?>
        <p style="font-size: 0.9rem; color: #ccc;">Create Guest Access Key</p>
        <?php if($msg): ?><div style="color:#e06c75; font-size:0.7rem; border:1px solid #e06c75; padding:5px; margin-bottom:10px;"><?= $msg ?></div><?php endif; ?>
        
        <form method="POST">
            <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <label style="color:#6a9c6a; font-size:0.7rem;">DURATION (HOURS):</label>
                <input type="number" name="duration" value="1" min="1" max="24" required>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%;">GENERATE KEY</button>
        </form>
    <?php endif; ?>

</body>
</html>