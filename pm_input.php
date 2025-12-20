<?php
session_start();
require 'db_config.php';

// Security Check
if (!isset($_SESSION['fully_authenticated']) || !isset($_GET['to'])) exit;

$my_id = $_SESSION['user_id'];
$target_id = (int)$_GET['to'];

// --- HANDLE SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Send Message
    if (isset($_POST['send_msg'])) {
        $body = trim($_POST['message']);
        if ($body) {
            // Auto PGP Wrap logic
            if (strpos($body, '-----BEGIN PGP MESSAGE-----') !== false && strpos($body, '[pgp]') === false) {
                $body = "[pgp]\n" . $body . "\n[/pgp]";
            }
            $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")
                ->execute([$my_id, $target_id, $body]);
        }
    }

    // 2. Initiate Burn
    if (isset($_POST['request_burn'])) {
        $req_txt = "[SYSTEM::BURN_REQUEST]";
        $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")
            ->execute([$my_id, $target_id, $req_txt]);
    }

    // 3. Confirm Burn
    if (isset($_POST['confirm_burn'])) {
        $pdo->prepare("DELETE FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)")
            ->execute([$my_id, $target_id, $target_id, $my_id]);
    }

    // 4. Cancel Burn
    if (isset($_POST['cancel_burn'])) {
        $pdo->prepare("DELETE FROM private_messages WHERE (sender_id = ? OR receiver_id = ?) AND message = '[SYSTEM::BURN_REQUEST]'")
            ->execute([$my_id, $my_id]);
    }
    
    // Redirect to self to clear POST data
    header("Location: pm_input.php?to=$target_id");
    exit;
}

// --- CHECK BURN STATUS ---
$is_burning = false;
$chk = $pdo->prepare("SELECT count(*) FROM private_messages WHERE (sender_id=? OR receiver_id=?) AND message='[SYSTEM::BURN_REQUEST]'");
$chk->execute([$target_id, $target_id]);
if ($chk->fetchColumn() > 0) $is_burning = true;
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #111; margin: 0; padding: 15px; font-family: monospace; overflow: hidden; }
        form { display: flex; flex-direction: column; gap: 10px; height: 100%; }
        .input-row { display: flex; gap: 10px; }
        
        input[type="text"] {
            flex-grow: 1; background: #000; color: #fff; border: 1px solid #333; 
            padding: 12px; font-family: monospace; outline: none;
        }
        input[type="text"]:focus { border-color: #6a9c6a; }
        
        .toolbar { display: flex; justify-content: flex-end; gap: 10px; align-items: center; }
        
        button { cursor: pointer; font-family: monospace; font-weight: bold; font-size: 0.7rem; padding: 5px 15px; }
        .btn-send { background: #1a1a1a; color: #6a9c6a; border: 1px solid #333; height: 42px; padding: 0 25px; }
        .btn-send:hover { background: #6a9c6a; color: #000; }
        
        .btn-burn { background: #1a0505; color: #666; border: 1px solid #333; }
        .btn-burn:hover { color: #e06c75; border-color: #e06c75; }
        
        .btn-active-burn { background: #220505; color: #e06c75; border: 1px solid #e06c75; animation: pulse 2s infinite; }
        .btn-cancel { background: #111; color: #888; border: 1px solid #444; }
        
        @keyframes pulse { 0% {opacity:1;} 50% {opacity:0.7;} 100% {opacity:1;} }
    </style>
</head>
<body>
    <form method="POST" autocomplete="off">
        <div class="input-row">
            <input type="text" name="message" required autofocus placeholder="Type secure message...">
            <button type="submit" name="send_msg" class="btn-send">SEND</button>
        </div>
        
        <div class="toolbar">
            <?php if($is_burning): ?>
                <span style="color:#e06c75; font-size:0.7rem;">⚠ WIPE REQUESTED</span>
                <input type="hidden" name="confirm_burn" value="1">
                <button type="submit" class="btn-active-burn">CONFIRM WIPE</button>
                <button type="submit" name="cancel_burn" class="btn-cancel" title="Cancel Request">[ X ]</button>
            <?php else: ?>
                <button type="submit" name="request_burn" class="btn-burn" title="Delete conversation for both parties">INITIATE WIPE</button>
            <?php endif; ?>
        </div>
    </form>
</body>
</html>