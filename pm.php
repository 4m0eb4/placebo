<?php
session_start();
require 'db_config.php';
require 'bbcode.php';

// Access Control
if (!isset($_SESSION['fully_authenticated']) || isset($_SESSION['is_guest'])) { 
    header("Location: index.php"); exit; 
}

$my_id = $_SESSION['user_id'];
$target_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;
$msg = "";

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_msg']) && $target_id) {
        $body = trim($_POST['message']);
        if ($body) {
            $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")->execute([$my_id, $target_id, $body]);
        }
    }
    if (isset($_POST['request_burn']) && $target_id) {
        $req_txt = "[SYSTEM::BURN_REQUEST]";
        $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")->execute([$my_id, $target_id, $req_txt]);
        $msg = "DESTRUCTION PROPOSAL SENT.";
    }
    if (isset($_POST['confirm_burn']) && $target_id) {
        $pdo->prepare("DELETE FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)")->execute([$my_id, $target_id, $target_id, $my_id]);
        $msg = "HISTORY ERASED.";
    }
}

// --- VIEW LOGIC ---
if ($target_id) {
    // CONVERSATION MODE
    $stmt_u = $pdo->prepare("SELECT username, pgp_public_key FROM users WHERE id = ?");
    $stmt_u->execute([$target_id]);
    $target_user = $stmt_u->fetch();
    
    if (!$target_user) die("Target Lost.");

    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")->execute([$target_id, $my_id]);

    $stmt_h = $pdo->prepare("SELECT * FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC");
    $stmt_h->execute([$my_id, $target_id, $target_id, $my_id]);
    $history = $stmt_h->fetchAll();

} else {
    // INBOX MODE
    $stmt_inbox = $pdo->prepare("SELECT DISTINCT u.id, u.username, u.rank FROM users u JOIN private_messages pm ON (u.id = pm.sender_id OR u.id = pm.receiver_id) WHERE (pm.receiver_id = ? OR pm.sender_id = ?) AND u.id != ? ORDER BY pm.created_at DESC");
    $stmt_inbox->execute([$my_id, $my_id, $my_id]);
    $contacts = $stmt_inbox->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Secure Comms</title>
    <link rel="stylesheet" href="style.css">
<style>
        .pm-container { width: 100%; max-width: 800px; margin: 20px auto; }
        .inbox-card { background: #080808; border: 1px solid #222; padding: 15px; margin-bottom: 15px; }
        .pm-meta { font-size: 0.65rem; color: #6a9c6a; margin-bottom: 8px; border-bottom: 1px solid #111; padding-bottom: 5px; }
        
        /* PGP ACCORDION */
        details.pgp-top { background: #080808; border: 1px solid #333; border-top: none; margin-bottom: 20px; }
        details.pgp-top summary { 
            padding: 8px 15px; cursor: pointer; color: #666; font-size: 0.65rem; font-family: monospace; 
            background: #111; border-bottom: 1px solid #333; user-select: none;
        }
        details.pgp-top summary:hover { color: #6a9c6a; }
        details.pgp-top[open] summary { color: #6a9c6a; border-bottom: 1px solid #333; }
        .pgp-content { padding: 10px; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display:block;">

<div class="main-container" style="width: 800px; margin: 0 auto;">
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <a href="index.php" class="term-logo">Placebo</a>
            <span style="color:#444; font-size:0.75rem; font-family:monospace;">// Secure_Comms</span>
        </div>
        <div style="font-size:0.75rem; font-family:monospace;">
            <?php if($target_id): ?>
                <a href="pm.php" style="color:#e5c07b; margin-right:15px; text-decoration:none;">&lt; INBOX</a>
            <?php endif; ?>
            <a href="chat.php" style="color:#888; text-decoration:none;">[ CHAT ]</a>
        </div>
    </div>

    <div class="content-area" style="padding: 0; background: #0d0d0d; min-height: 80vh; display:flex; flex-direction:column;">
        
        <?php if($target_id): ?>
            <div style="padding:15px; border-bottom:1px solid #333; background:#111; display:flex; justify-content:space-between; align-items:center;">
                <span style="color:#fff; font-weight:bold;">UPLINK: <?= htmlspecialchars($target_user['username']) ?></span>
                <form method="POST" onsubmit="return confirm('Request Mutual Destruction?');" style="margin:0;">
                    <button type="submit" name="request_burn" class="badge" style="background:#220505; color:#e06c75; border:1px solid #e06c75; cursor:pointer;">INITIATE WIPE</button>
                </form>
            </div>

            <details class="pgp-top">
                <summary>[ VIEW TARGET PGP IDENTITY ]</summary>
                <div class="pgp-content">
                     <textarea readonly class="pgp-box" style="height:120px; width:100%; border:none;"><?= htmlspecialchars($target_user['pgp_public_key']) ?></textarea>
                </div>
            </details>

            <div class="pm-thread" style="flex:1; overflow-y:auto; max-height:600px; padding-top:10px;">
                <?php if($msg): ?><div class="success" style="text-align:center;"><?= $msg ?></div><?php endif; ?>
                
                <?php if(empty($history)): ?>
                    <div style="text-align:center; color:#444; margin-top:50px;">- CHANNEL SECURE. NO RECORDS FOUND. -</div>
                <?php endif; ?>

                <?php foreach($history as $m): ?>
                    <?php if($m['message'] === '[SYSTEM::BURN_REQUEST]'): ?>
                        <div class="burn-request">
                            <?php if($m['sender_id'] == $my_id): ?>
                                <div>WAITING FOR PARTNER APPROVAL TO WIPE...</div>
                            <?php else: ?>
                                <div style="margin-bottom:10px;">PARTNER REQUESTED HISTORY DELETION</div>
                                <form method="POST">
                                    <button type="submit" name="confirm_burn" class="btn-burn">AGREE & BURN RECORDS</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php $is_me = ($m['sender_id'] == $my_id); ?>
                        <div class="pm-msg <?= $is_me ? 'pm-sent' : 'pm-rec' ?>">
                            <div class="pm-header"><?= $is_me ? 'YOU' : htmlspecialchars($target_user['username']) ?> | <?= date('H:i', strtotime($m['created_at'])) ?></div>
                            <div style="font-family:monospace; white-space:pre-wrap;"><?= parse_bbcode($m['message']) ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div style="padding:20px; border-top:1px solid #333; background:#111;">
                <form method="POST" style="display:flex; gap:10px;">
                    <textarea name="message" required style="flex-grow:1; background:#000; color:#fff; border:1px solid #333; padding:10px; font-family:monospace; height:60px;" placeholder="Type secure message..."></textarea>
                    <button type="submit" name="send_msg" class="btn-primary" style="width:auto; padding:0 20px;">SEND</button>
                </form>
            </div>

        <?php else: ?>
            <div style="padding:20px;">
                <h3 style="color:#d19a66; border-bottom:1px solid #333; padding-bottom:10px;">ACTIVE FREQUENCIES</h3>
                <?php if(empty($contacts)): ?>
                    <div style="color:#444;">No prior communications.</div>
                <?php endif; ?>

                <?php foreach($contacts as $c): ?>
                    <a href="pm.php?to=<?= $c['id'] ?>" class="inbox-row">
                        <div>
                            <span style="color:#fff; font-weight:bold;"><?= htmlspecialchars($c['username']) ?></span>
                            <span class="badge" style="margin-left:5px;">L<?= $c['rank'] ?></span>
                        </div>
                        <div style="color:#666; font-size:0.7rem;">[ OPEN CHANNEL ]</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>