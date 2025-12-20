<?php
session_start();
require 'db_config.php';
require 'bbcode.php';

// Access Control: Only fully authenticated registered users can use PMs
if (!isset($_SESSION['fully_authenticated']) || isset($_SESSION['is_guest'])) { 
    header("Location: index.php"); exit; 
}

// --- PART 1: SENDING LOGIC ---
$target_id = $_GET['to'] ?? 0;
$recipient = null;

if ($target_id) {
    $stmt = $pdo->prepare("SELECT id, username, pgp_public_key FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $recipient = $stmt->fetch();
}

$msg = "";
// HANDLE SEND
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_send'])) {
    $content = trim($_POST['message']);
    if ($content && $recipient) {
        $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $recipient['id'], $content]);
        $msg = "MESSAGE DISPATCHED.";
    }
}

// HANDLE DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pm'])) {
    // Only delete if I am the receiver
    $stmt = $pdo->prepare("DELETE FROM private_messages WHERE id = ? AND receiver_id = ?");
    $stmt->execute([$_POST['pm_id'], $_SESSION['user_id']]);
    $msg = "SIGNAL ERASED.";
}

// --- PART 2: INBOX FETCHING ---
$stmt_inbox = $pdo->prepare("
    SELECT pm.*, u.username as sender_name 
    FROM private_messages pm 
    JOIN users u ON pm.sender_id = u.id 
    WHERE pm.receiver_id = ? 
    ORDER BY pm.created_at DESC
");
$stmt_inbox->execute([$_SESSION['user_id']]);
$my_messages = $stmt_inbox->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Secure Communications</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pm-container { width: 100%; max-width: 800px; margin: 20px auto; }
        .inbox-card { background: #080808; border: 1px solid #222; padding: 15px; margin-bottom: 15px; }
        .pm-meta { font-size: 0.65rem; color: #6a9c6a; margin-bottom: 8px; border-bottom: 1px solid #111; padding-bottom: 5px; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display:block;">

<div class="main-container" style="width: 850px; margin: 0 auto;">
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <a href="index.php" class="term-logo">Placebo</a>
            <span style="color:#444; font-size:0.75rem; font-family:monospace;">// Secure_Comms</span>
        </div>
        <a href="chat.php" style="color:#888; font-size:0.75rem; text-decoration:none;">&lt; RETURN TO CHAT</a>
    </div>

    <div class="content-area" style="padding: 30px; background: #0d0d0d; min-height: 80vh;">
        
        <?php if ($recipient): ?>
            <div class="login-wrapper" style="width:100%; max-width:700px; margin:0 auto 40px auto; border: 1px solid #333;">
                <div class="terminal-header">
                    <span>COMMS_OUTBOUND // TO: <?= htmlspecialchars($recipient['username']) ?></span>
                </div>
                <div style="padding:20px;">
                    <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>
                    
<div class="input-group">
    <label>RECIPIENT PGP PUBLIC KEY</label>
    <details style="background:#080808; border:1px solid #333; padding:10px;">
        <summary style="font-size:0.7rem; color:#6a9c6a; cursor:pointer; outline:none;">[ CLICK TO VIEW / COPY KEY ]</summary>
        <textarea readonly class="pgp-box" style="height:120px; font-size:0.6rem; color:#888; border:none; width:100%; margin-top:10px;"><?= htmlspecialchars($recipient['pgp_public_key']) ?></textarea>
    </details>
    <small style="color:#444; font-size:0.6rem;">Wrap your message manually using this key before sending.</small>
</div>
                    <form method="POST">
                        <div class="input-group">
                            <label>ENCRYPTED PAYLOAD</label>
                            <textarea name="message" class="pgp-box" style="height:200px; background:#000; border-color:#6a9c6a;" placeholder="-----BEGIN PGP MESSAGE-----" required></textarea>
                        </div>
                        <button type="submit" name="action_send" class="btn-primary">DISPATCH SIGNAL</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="pm-container">
            <h3 class="section-head" style="color:#d19a66; border-bottom: 1px solid #333; padding-bottom:10px; margin-bottom:20px;">
                INCOMING ENCRYPTED SIGNALS
            </h3>
            
            <?php if(empty($my_messages)): ?>
                <div style="text-align:center; padding:40px; color:#444; font-style:italic;">[ NO SIGNALS DETECTED IN LOCAL BUFFER ]</div>
            <?php else: ?>
                <?php foreach($my_messages as $m): ?>
                    <div class="inbox-card">
                        <div class="pm-meta">
                            FROM: <span style="color:#fff;"><?= htmlspecialchars($m['sender_name']) ?></span> 
                            // RECEIVED: <?= date('Y-m-d H:i', strtotime($m['created_at'])) ?>
                        </div>
                        <pre class="bb-pgp-block" style="font-size:0.7rem; color:#6a9c6a;"><?= htmlspecialchars($m['message']) ?></pre>
                        <div style="margin-top:10px; display:flex; justify-content:flex-end; gap:10px;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="pm_id" value="<?= $m['id'] ?>">
                                <button type="submit" name="delete_pm" style="background:none; border:none; color:#444; cursor:pointer; font-size:0.65rem; font-family:monospace;">[ DELETE ]</button>
                            </form>
                            <a href="pm.php?to=<?= $m['sender_id'] ?>" class="btn-mini" style="color:#d19a66; text-decoration:none; border:1px solid #d19a66; padding:2px 8px;">REPLY</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>