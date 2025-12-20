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
            // Auto PGP Wrap
            if (strpos($body, '-----BEGIN PGP MESSAGE-----') !== false && strpos($body, '[pgp]') === false) {
                $body = "[pgp]\n" . $body . "\n[/pgp]";
            }
            $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")->execute([$my_id, $target_id, $body]);
        }
    }
    if (isset($_POST['request_burn']) && $target_id) {
        $req_txt = "[SYSTEM::BURN_REQUEST]";
        $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")->execute([$my_id, $target_id, $req_txt]);
        // No message needed, stream shows it
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

} else {
    // INBOX MODE
    $stmt_inbox = $pdo->prepare("SELECT DISTINCT u.id, u.username, u.rank, 
        (SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND sender_id = u.id AND is_read = 0) as unread
        FROM users u JOIN private_messages pm ON (u.id = pm.sender_id OR u.id = pm.receiver_id) 
        WHERE (pm.receiver_id = ? OR pm.sender_id = ?) AND u.id != ? ORDER BY unread DESC, pm.created_at DESC");
    $stmt_inbox->execute([$my_id, $my_id, $my_id, $my_id]);
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
        
        /* PGP ACCORDION */
        details.pgp-top { background: #080808; border: 1px solid #333; border-top: none; margin-bottom: 20px; }
        details.pgp-top summary { 
            padding: 8px 15px; cursor: pointer; color: #666; font-size: 0.65rem; font-family: monospace; 
            background: #111; border-bottom: 1px solid #333; user-select: none;
        }
        details.pgp-top summary:hover { color: #6a9c6a; }
        details.pgp-top[open] summary { color: #6a9c6a; border-bottom: 1px solid #333; }
        .pgp-content { padding: 10px; }

        /* BURN BUTTON */
        .btn-burn-active {
            background: #220505; color: #e06c75; border: 1px solid #e06c75; 
            cursor: pointer; animation: pulse 2s infinite; font-size: 0.7rem; padding: 2px 8px;
        }
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
                
                <form method="POST" onsubmit="return confirm('INITIATE DESTRUCTION?');" style="margin:0;">
                    <?php
                        // Check if burn requested
                        $chk = $pdo->prepare("SELECT count(*) FROM private_messages WHERE (sender_id=? OR receiver_id=?) AND message='[SYSTEM::BURN_REQUEST]'");
                        $chk->execute([$target_id, $target_id]);
                        $is_burning = $chk->fetchColumn();
                    ?>
                    <?php if($is_burning): ?>
                        <input type="hidden" name="confirm_burn" value="1">
                        <button type="submit" class="btn-burn-active">⚠ CONFIRM WIPE ⚠</button>
                    <?php else: ?>
                        <button type="submit" name="request_burn" class="badge" style="background:#1a0505; color:#666; border:1px solid #333; cursor:pointer;">INITIATE WIPE</button>
                    <?php endif; ?>
                </form>
            </div>

            <details class="pgp-top">
                <summary>[ VIEW TARGET PGP IDENTITY ]</summary>
                <div class="pgp-content">
                     <textarea readonly class="pgp-box" style="height:120px; width:100%; border:none;"><?= htmlspecialchars($target_user['pgp_public_key']) ?></textarea>
                </div>
            </details>

            <div style="flex:1; background:#0d0d0d; position:relative;">
                <iframe src="pm_stream.php?to=<?= $target_id ?>" style="position:absolute; top:0; left:0; width:100%; height:100%; border:none;"></iframe>
            </div>

            <div style="padding:20px; border-top:1px solid #333; background:#111;">
                <form method="POST" style="display:flex; gap:10px;" autocomplete="off">
                    <input type="text" name="message" required autofocus style="flex-grow:1; background:#000; color:#fff; border:1px solid #333; padding:12px; font-family:monospace; outline:none;" placeholder="Type secure message...">
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
                    <a href="pm.php?to=<?= $c['id'] ?>" style="display:block; background:#161616; padding:12px; margin-bottom:8px; border:1px solid #333; text-decoration:none; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <span style="color:#fff; font-weight:bold;"><?= htmlspecialchars($c['username']) ?></span>
                            <span class="badge" style="margin-left:5px;">L<?= $c['rank'] ?></span>
                        </div>
                        <?php if($c['unread'] > 0): ?>
                            <div style="color:#e06c75; font-weight:bold; font-size:0.7rem;">[ <?= $c['unread'] ?> UNREAD ]</div>
                        <?php else: ?>
                            <div style="color:#444; font-size:0.7rem;">[ OPEN ]</div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>