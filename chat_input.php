<?php
session_start();
require 'db_config.php';

// --- SECURITY: STRICT SESSION CHECK (Trap Door) ---
$kill = false;
if (!isset($_SESSION['fully_authenticated'])) {
    $kill = true;
} else {
    // Check Guest Status
    if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
        $stmt = $pdo->prepare("SELECT status FROM guest_tokens WHERE id = ?");
        $stmt->execute([$_SESSION['guest_token_id'] ?? 0]);
        if ($stmt->fetchColumn() !== 'active') $kill = true;
    } 
    // Check Registered Status
    else {
        $stmt = $pdo->prepare("SELECT is_banned, force_logout FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $u = $stmt->fetch();
        if ($u && ($u['is_banned'] == 1 || $u['force_logout'] == 1)) $kill = true;
    }
}

// IF DEAD: Immediate Halt
if ($kill) {
    echo "<style>
        html, body { background: #000 !important; margin: 0; padding: 0; height: 100%; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .kill-msg { 
            color: #e06c75; font-family: monospace; font-weight: bold; text-decoration: none; 
            border: 1px solid #e06c75; padding: 10px 20px; background: #1a0505; text-transform: uppercase;
        }
        form, input, button { display: none !important; }
    </style>";
    echo "<a href='terminated.php' target='_top' class='kill-msg'>🚫 SIGNAL LOST // EXIT</a>";
    exit;
}

// Emoji Map
function parse_emojis($text) {
    $map = [
        ':)' => '😊', ':(' => '☹️', ':D' => '😃', ';)' => '😉',
        '<3' => '❤️', ':cool:' => '😎', ':skull:' => '💀',
        ':fire:' => '🔥', ':check:' => '✅', ':x:' => '❌'
    ];
    return str_replace(array_keys($map), array_values($map), $text);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    
    // Auto-inject quote
    if (!empty($_POST['quote_data'])) {
        $msg = $msg . $_POST['quote_data'];
    }

    if (!empty($msg)) {
        // 1. Determine Color
        $color = '#888888';
        if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
            $color = $_SESSION['guest_color'] ?? '#888888';
        } else {
            $stmt = $pdo->prepare("SELECT chat_color FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $color = $stmt->fetchColumn() ?: '#888888';
        }

        // 2. Parse Emojis
        $msg = parse_emojis($msg);

        // 3. LINK INTERCEPT (Tor-friendly regex)
        // Catches http/https links. Saves original message to 'shared_links' and STOPS chat insertion.
        if (preg_match('/(https?:\/\/[^\s]+)/i', $msg, $matches)) {
            $url = $matches[1];
            
            try {
                // Insert into Pending Links with the ORIGINAL message content
                $stmt_l = $pdo->prepare("INSERT INTO shared_links (url, posted_by, status, original_message) VALUES (?, ?, 'pending', ?)");
                $stmt_l->execute([$url, $_SESSION['username'], $msg]);
                
                // Feedback to user (Only they see this redirect)
                // We use a specific parameter to show a localized success message if you wanted, 
                // but for now we just reload.
            } catch (Exception $e) { }
            
            header("Location: chat_input.php?status=link_pending"); 
            exit;
        }

        // 4. Normal Message Insert
        try {
            $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, color_hex, msg_type) VALUES (?, ?, ?, ?, ?, 'normal')");
            $stmt->execute([$_SESSION['user_id'] ?? 0, $_SESSION['username'], $msg, $_SESSION['rank'] ?? 0, $color]);
        } catch (PDOException $e) { }
        
        header("Location: chat_input.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #0d0d0d; margin: 0; padding: 15px 20px; overflow: hidden; font-family: monospace; }
        form { display: flex; width: 100%; }
        input { flex: 1; background: #111; border: 1px solid #333; color: #eee; padding: 12px; font-family: inherit; outline: none; }
        input:focus { border-color: #555; background: #000; }
        button { background: #222; border: 1px solid #333; border-left: none; color: #6a9c6a; padding: 0 25px; cursor: pointer; font-weight: bold; }
        button:hover { background: #6a9c6a; color: #000; }
        .info-bar { font-size: 0.7rem; padding: 5px 10px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; }
        .pending-msg { background: #1a1005; color: #e5c07b; border: 1px dashed #e5c07b; }
        .reply-msg { background: #1a0505; color: #e06c75; border: 1px solid #e06c75; border-bottom: none; }
    </style>
</head>
<body style="background: #0d0d0d; margin: 0; padding: 5px; font-family: monospace;">
    <?php
    // Check for Unread PMs (Persistent)
    $pm_count = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $pm_count = $stmt->fetchColumn();
    } catch(Exception $e){}
    ?>

<?php if($pm_count > 0): ?>
        <div class="info-bar" style="background: #1a0505; color: #e06c75; border: 1px solid #e06c75; border-bottom: none;">
            <span><strong>ENCRYPTED SIGNAL:</strong> <?= $pm_count ?> Unread Message(s)</span>
            <a href="pm.php" target="_blank" style="color: inherit; text-decoration: underline; font-weight:bold;">[ DECRYPT ]</a>
        </div>
    <?php endif; ?>
    <?php
    $reply_user = isset($_GET['reply_user']) ? htmlspecialchars($_GET['reply_user']) : '';
    $reply_text = isset($_GET['reply_text']) ? htmlspecialchars($_GET['reply_text']) : '';
    $is_reply = !empty($reply_user);
    $link_pending = (isset($_GET['status']) && $_GET['status'] == 'link_pending');
    ?>

    <form method="POST" autocomplete="off" style="display: flex; flex-direction: column; width: 100%;">
        
        <?php if($link_pending): ?>
            <div class="info-bar pending-msg">
                <span>[INFO] Link detained for moderation. Message will appear once approved.</span>
                <a href="chat_input.php" style="color: inherit; text-decoration: none;">[X]</a>
            </div>
        <?php endif; ?>

        <?php if($is_reply): ?>
            <div class="info-bar reply-msg">
                <span>Replying to: <strong><?= $reply_user ?></strong></span>
                <a href="chat_input.php" style="color: #888; text-decoration: none;">[ CANCEL ]</a>
            </div>
            <input type="hidden" name="quote_data" value="<?= "\n[quote=" . $reply_user . "]" . $reply_text . "[/quote]" ?>">
        <?php endif; ?>
        
        <div style="display: flex; border: 1px solid #333; background: #000;">
            <a href="help_bbcode.php" target="chat_stream" style="background: #161616; color: #6a9c6a; border-right: 1px solid #333; padding: 0 15px; font-weight: bold; font-size: 1rem; display: flex; align-items: center; text-decoration: none;" title="View Codes">
                [?]
            </a>

            <input type="text" name="message" placeholder="Type a message..." autofocus required 
                   style="flex-grow: 1; background: transparent; border: none; color: #ffffff !important; padding: 12px; font-family: inherit; outline: none; caret-color: #6a9c6a;">
            <button type="submit" style="background: #1a1a1a; color: #6a9c6a; border: none; border-left: 1px solid #333; padding: 0 30px; cursor: pointer; font-weight: bold; font-size: 0.8rem;">SEND</button>
        </div>
    </form>
</body>
</html>