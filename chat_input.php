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
        html, body { background: #000 !important; margin: 0; padding: 0; height: 100%; overflow: hidden; display: flex; align-items: center; justify-content: center; z-index: 99999; }
        .kill-msg { 
            color: #e06c75; font-family: monospace; font-weight: bold; text-decoration: none; 
            border: 1px solid #e06c75; padding: 10px 20px; background: #1a0505; text-transform: uppercase; cursor: pointer;
        }
        form, input, button, .info-bar { display: none !important; }
    </style>";
    // Target _top breaks the iframe, sending the user to logout/index cleanly
    echo "<a href='logout.php' target='_top' class='kill-msg'>🚫 SIGNAL LOST // EXIT</a>";
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

// --- FETCH CHAT SETTINGS ---
$c_stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('chat_locked', 'chat_slow_mode')");
$c_conf = [];
while($r = $c_stmt->fetch()) $c_conf[$r['setting_key']] = $r['setting_value'];

$is_locked = ($c_conf['chat_locked'] ?? '0') === '1';
$slow_sec = (int)($c_conf['chat_slow_mode'] ?? 0);
$my_rank = $_SESSION['rank'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 0. ENFORCE LOCK
    if ($is_locked && $my_rank < 9) {
        die("LOCKED"); // Simple block, UI handled below
    }

    // 0.5 ENFORCE SLOW MODE
    if ($slow_sec > 0 && $my_rank < 9) {
        $l_stmt = $pdo->prepare("SELECT created_at FROM chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $l_stmt->execute([$_SESSION['user_id']]);
        if ($last_time = $l_stmt->fetchColumn()) {
            $diff = time() - strtotime($last_time);
            if ($diff < $slow_sec) {
                // Too fast
                 header("Location: chat_input.php?error=slow"); exit;
            }
        }
    }

    $msg = trim($_POST['message'] ?? '');
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
        // Catches http/https links AND raw .onion addresses (V2/V3).
        // BYPASS: Admins (Rank 9+) skip this and post directly.
        $is_admin = (isset($_SESSION['rank']) && $_SESSION['rank'] >= 9);
        
        // Regex matches standard URLs OR .onion strings (16-56 lowercase/numbers)
        if (!$is_admin && preg_match('/(https?:\/\/[^\s]+|[a-z2-7]{16,56}\.onion)/i', $msg, $matches)) {
            $url = $matches[0];
            
            // Fix raw onion links by prepending http://
            if (strpos($url, 'http') !== 0 && strpos($url, '.onion') !== false) {
                $url = 'http://' . $url;
            }

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
        /* FLEXBOX LAYOUT: Ensures Notification Bar pushes the Form down naturally */
        html, body { 
            background: #0d0d0d; margin: 0; padding: 0; height: 100%; width: 100%;
            overflow: hidden; font-family: monospace; 
            display: flex; flex-direction: column; 
        }
        
        .info-bar {
            flex-shrink: 0; /* Fixed height */
            width: 100%; 
            background: #1a1005 !important; 
            color: #e5c07b !important; 
            border-bottom: 1px solid #e5c07b !important;
            padding: 5px 10px; 
            font-size: 0.65rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-sizing: border-box;
        }
        .info-bar a { color: #fff; text-decoration: none; font-weight: bold; }

        /* Form fills remaining space */
        form { 
            flex: 1; 
            display: flex; 
            width: 100%; 
            align-items: center; /* Vertically Center the input */
            padding: 5px; 
            box-sizing: border-box; 
        }
        input { flex: 1; background: #111; border: 1px solid #333; color: #eee; padding: 8px; font-family: inherit; outline: none; height: 30px; box-sizing: border-box; }
        input:focus { border-color: #555; background: #000; }
        button { background: #222; border: 1px solid #333; border-left: none; color: #6a9c6a; padding: 0 15px; cursor: pointer; font-weight: bold; font-size: 0.7rem; height: 30px; }
        button:hover { background: #6a9c6a; color: #000; }
    </style>
</head>
<body>

    <?php if($link_pending): ?>
        <div class="info-bar">
            <span>[INFO] LINK DETAINED FOR MODERATION</span>
            <a href="chat_input.php">[ X ]</a>
        </div>
        <?php endif; ?>

<?php if($is_locked && $my_rank < 9): ?>
        <div style="display:flex; align-items:center; justify-content:center; width:100%; height:100%; background:#1a0505; color:#e06c75; font-weight:bold; font-size:0.8rem; border:1px solid #e06c75;">
            [ CHAT LOCKED BY ADMIN ]
        </div>
    <?php else: ?>
        
        <?php if($is_reply): ?>
            <div style="width:100%; background:#0d0d0d; border-bottom:1px solid #d19a66; border-left: 4px solid #d19a66; color:#d19a66; font-size:0.65rem; padding:4px 10px; font-family:monospace; font-weight:bold; box-sizing:border-box;">
                >> REPLYING TO: <span style="color:#fff;"><?= htmlspecialchars($reply_user) ?></span>
                <a href="chat_input.php" style="float:right; color:#e06c75; text-decoration:none;">[CANCEL]</a>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?php if($is_reply): ?>
                <input type="hidden" name="quote_data" value="<?= "\n[quote=" . htmlspecialchars($reply_user) . "]" . htmlspecialchars($reply_text) . "[/quote]" ?>">
            <?php endif; ?>
            
            <div style="display: flex; width: 100%;">
<a href="help_bbcode.php" target="_blank" style="background: #161616; color: #6a9c6a; border: 1px solid #333; border-right: none; padding: 0 10px; font-weight: bold; font-size: 1rem; display: flex; align-items: center; text-decoration: none; height: 30px; box-sizing: border-box;" title="Open Formatting Guide">[?]</a>
            
            <?php 
                $ph = "Type a message...";
                    if ($slow_sec > 0 && $my_rank < 9) $ph = "Slow Mode: {$slow_sec}s delay active."; 
                ?>
                <input type="text" name="message" placeholder="<?= $ph ?>" autofocus required>
                <button type="submit">SEND</button>
            </div>
        </form>
    <?php endif; ?>
    <div style="background: #161616; border: 1px solid #333; width: 100%; max-width: 400px; padding: 15px; position: relative;">
        <a href="chat.php" style="position: absolute; top: 8px; right: 10px; color: #666; text-decoration: none; font-size:0.8rem;">[ CLOSE ]</a>
        
        <h2 style="color: #6a9c6a; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 5px; font-size: 1rem; margin-bottom: 15px;">INVITE SYSTEM</h2>
</body>
</html>