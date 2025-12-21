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

// Notification & Reply State
$link_pending = (isset($_GET['status']) && $_GET['status'] === 'link_pending');
$reply_user = $_GET['reply_user'] ?? '';
$reply_text = $_GET['reply_text'] ?? '';
$is_reply = ($reply_user && $reply_text);

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
        
        // 3. LINK INTERCEPT
        // Catches http/https links. 
        // BYPASS: Admins (Rank 9+) skip this and post directly.
        $is_admin = (isset($_SESSION['rank']) && $_SESSION['rank'] >= 9);
        
        if (!$is_admin && preg_match('/(https?:\/\/[^\s]+)/i', $msg, $matches)) {
            $url = $matches[1];
            try {
                $stmt_l = $pdo->prepare("INSERT INTO shared_links (url, posted_by, status, original_message) VALUES (?, ?, 'pending', ?)");
                $stmt_l->execute([$url, $_SESSION['username'], $msg]);
            } catch (Exception $e) { }
            
            header("Location: chat_input.php?status=link_pending"); 
            exit;
        }

        // 4. Normal Message Insert
        try {
            $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, color_hex, msg_type) VALUES (?, ?, ?, ?, ?, 'normal')");
            $stmt->execute([$_SESSION['user_id'] ?? 0, $_SESSION['username'], $msg, $_SESSION['rank'] ?? 0, $color]);
            
            // --- AUTO PRUNE ---
            $limit_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'max_chat_history'");
            $limit = (int)($limit_stmt->fetchColumn() ?: 150);
            
            $cnt = $pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();
            if ($cnt > $limit) {
                // Delete everything OLDER than the Nth newest message
                $pdo->exec("DELETE FROM chat_messages WHERE id <= (
                    SELECT id FROM (SELECT id FROM chat_messages ORDER BY id DESC LIMIT 1 OFFSET $limit) tmp
                )");
                // Signal Purge to refresh clients
                $pdo->prepare("INSERT INTO chat_signals (signal_type) VALUES ('PURGE')")->execute();
            }
        } catch (Exception $e) { }
        
        header("Location: chat_input.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
/* Use fixed height and hidden overflow to prevent any scrolling */
        html, body { background: #0d0d0d; margin: 0; padding: 0; height: 100%; overflow: hidden; font-family: monospace; }
        
        .info-bar {
            /* Fix to the top without taking up space in the layout flow */
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 22px; 
            background: #1a1005 !important; 
            color: #e5c07b !important; 
            border-bottom: 1px solid #e5c07b !important;
            padding: 0 10px; 
            font-size: 0.65rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            z-index: 100; 
            box-sizing: border-box;
        }

        /* Ensure the form stays at the bottom of the frame regardless of the bar */
        form { 
            display: flex; 
            width: 100%; 
            height: 100%; 
            align-items: flex-end; 
            padding: 5px; 
            box-sizing: border-box; 
        }
        .info-bar a { color: #fff; text-decoration: none; font-weight: bold; }

        form { display: flex; width: 100%; height: 100%; align-items: center; padding: 5px; box-sizing: border-box; }
        input { flex: 1; background: #111; border: 1px solid #333; color: #eee; padding: 8px; font-family: inherit; outline: none; height: 30px; box-sizing: border-box; }
        input:focus { border-color: #555; background: #000; }
        button { background: #222; border: 1px solid #333; border-left: none; color: #6a9c6a; padding: 0 15px; cursor: pointer; font-weight: bold; font-size: 0.7rem; height: 30px; }
        button:hover { background: #6a9c6a; color: #000; }
    </style>
</head>
<body style="background: #0d0d0d; margin: 0; padding: 0; font-family: monospace;">

    <?php if($link_pending): ?>
        <div class="info-bar">
            <span>[INFO] LINK DETAINED FOR MODERATION</span>
            <a href="chat_input.php">[ X ]</a>
        </div>
        <div style="height:22px;"></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?php if($is_reply): ?>
            <input type="hidden" name="quote_data" value="<?= "\n[quote=" . htmlspecialchars($reply_user) . "]" . htmlspecialchars($reply_text) . "[/quote]" ?>">
            <div style="position:absolute; top:-15px; left:45px; background:#222; color:#fff; font-size:0.6rem; padding:2px 5px; border-radius:3px 3px 0 0;">Replying to <?= htmlspecialchars($reply_user) ?>...</div>
        <?php endif; ?>
        
        <div style="display: flex; width: 100%;">
            <a href="help_bbcode.php" target="_blank" style="background: #161616; color: #6a9c6a; border: 1px solid #333; border-right: none; padding: 0 10px; font-weight: bold; font-size: 1rem; display: flex; align-items: center; text-decoration: none; height: 30px; box-sizing: border-box;" title="View Codes">[?]</a>
            <input type="text" name="message" placeholder="Type a message..." autofocus required>
            <button type="submit">SEND</button>
        </div>
    </form>
</body>
</html>