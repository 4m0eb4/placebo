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

// 5. GET ACTIONS (Deletion)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['msg_id'])) {
    $mid = (int)$_GET['msg_id'];
    // Verify ownership before deleting
    $del = $pdo->prepare("DELETE FROM private_messages WHERE id = ? AND sender_id = ?");
    $del->execute([$mid, $my_id]);
    header("Location: pm_input.php?to=$target_id");
    exit;
}

// --- CHECK BURN STATUS & ORIGIN ---
$burn_state = 'NONE'; // NONE, WAITING, CONFIRM

// Check if a burn request exists in this specific conversation
$chk = $pdo->prepare("SELECT id, sender_id FROM private_messages WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) AND message='[SYSTEM::BURN_REQUEST]' LIMIT 1");
$chk->execute([$my_id, $target_id, $target_id, $my_id]);
$burn_row = $chk->fetch();

if ($burn_row) {
    if ($burn_row['sender_id'] == $my_id) {
        $burn_state = 'WAITING'; // I sent the request
    } else {
        $burn_state = 'CONFIRM'; // They sent the request
    }
}

// HANDLE CANCELLATION (If I sent it, I can cancel it)
if (isset($_POST['cancel_burn']) && $burn_state === 'WAITING') {
    $pdo->prepare("DELETE FROM private_messages WHERE id = ?")->execute([$burn_row['id']]);
    header("Location: pm_input.php?to=$target_id"); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
   <style>
        body { background: #111; margin: 0; padding: 8px; font-family: monospace; overflow: hidden; }
        form { display: flex; flex-direction: column; gap: 4px; height: 100%; }
        .input-row { display: flex; gap: 4px; }
        
        input[type="text"] {
            flex-grow: 1; background: #000; color: #fff; border: 1px solid #333; 
            padding: 8px; font-family: monospace; outline: none; height: 30px;
        }
        button { height: 30px; font-size: 0.65rem; padding: 0 10px; }
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
            <input type="text" name="message" autofocus placeholder="Type secure message...">
            <button type="submit" name="send_msg" class="btn-send">SEND</button>
        </div>
<div class="toolbar" style="display:flex; align-items:center; gap:10px;">
            <?php if($burn_state === 'CONFIRM'): ?>
                <span style="color:#e06c75; font-size:0.7rem; font-weight:bold; animation:pulse 1s infinite;">⚠ PARTNER REQUESTED WIPE</span>
                <input type="hidden" name="confirm_burn" value="1">
                <button type="submit" class="btn-active-burn" style="background:#e06c75; color:#000; border:none; font-weight:bold;">CONFIRM & ERASE</button>

            <?php elseif($burn_state === 'WAITING'): ?>
                <span style="color:#e5c07b; font-size:0.7rem;">[ WAITING FOR PARTNER... ]</span>
                <button type="submit" name="cancel_burn" style="background:none; border:1px solid #444; color:#888; cursor:pointer; font-size:0.65rem; padding:2px 6px;">CANCEL</button>

            <?php else: ?>
                <button type="submit" name="request_burn" class="btn-burn" title="Permanently delete history for both sides">INITIATE WIPE</button>
            <?php endif; ?>
        </div>
    </form>
</body>
</html>