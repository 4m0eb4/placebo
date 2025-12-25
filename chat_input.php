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
        // [FIX] Fetch Status + Penalties (Mute/Slow)
        $stmt = $pdo->prepare("SELECT status, is_muted, slow_mode_override FROM guest_tokens WHERE id = ?");
        $stmt->execute([$_SESSION['guest_token_id'] ?? 0]);
        $g = $stmt->fetch();

        if (!$g || $g['status'] !== 'active') {
            $kill = true;
        } else {
            // Apply Guest Penalties to Session
            if (!empty($g['is_muted'])) $_SESSION['is_muted'] = true; else unset($_SESSION['is_muted']);
            if (!empty($g['slow_mode_override']) && $g['slow_mode_override'] > 0) $_SESSION['user_slow'] = (int)$g['slow_mode_override']; else unset($_SESSION['user_slow']);
        }
    } 
    // Check Registered Status
    else {
        $stmt = $pdo->prepare("SELECT is_banned, force_logout, is_muted, slow_mode_override FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $u = $stmt->fetch();
        if ($u) {
            if ($u['is_banned'] == 1 || $u['force_logout'] == 1) {
                $kill = true;
                $ban_reason = $u['ban_reason'] ?? 'Connection Reset.'; // Capture reason
            }
            if (isset($u['is_muted']) && $u['is_muted'] == 1) $_SESSION['is_muted'] = true; else unset($_SESSION['is_muted']);
            if (isset($u['slow_mode_override']) && $u['slow_mode_override'] > 0) $_SESSION['user_slow'] = (int)$u['slow_mode_override']; else unset($_SESSION['user_slow']);
        }
    }
}

// IF DEAD: Immediate Halt
if ($kill) {
    // Standardized Kill Screen (Matches Stream)
    echo "<style>html, body { background: #000 !important; overflow:hidden; display:flex; align-items:center; justify-content:center; height:100%; margin:0; } form, .info-bar, .reply-bar { display: none !important; }</style>";
    echo "<div style='text-align:center; font-family:monospace;'>
           <h1 style='color:#e06c75; margin:0 0 10px 0; font-size:1rem; letter-spacing:2px;'>SIGNAL LOST</h1>
           <a href='logout.php' target='_top' style='color:#fff; border:1px solid #e06c75; padding:5px 15px; text-decoration:none; font-weight:bold; background:#1a0505; font-size:0.7rem;'>[ TERMINATE SESSION ]</a>
          </div>";
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

// --- FETCH CHANNEL SETTINGS ---
$active_chan = $_SESSION['active_channel'] ?? 1;
$c_stmt = $pdo->prepare("SELECT is_locked, slow_mode, write_rank FROM chat_channels WHERE id = ?");
$c_stmt->execute([$active_chan]);
$chan_conf = $c_stmt->fetch();

// Fallback to Global Defaults if channel invalid
if (!$chan_conf) { $chan_conf = ['is_locked'=>0, 'slow_mode'=>0, 'write_rank'=>1]; }

$is_locked = ($chan_conf['is_locked'] == 1);
$lock_req  = 9; // Hardcoded Admin Override for Locks
$slow_sec  = (int)$chan_conf['slow_mode'];
$write_req = (int)$chan_conf['write_rank'];

$my_rank   = $_SESSION['rank'] ?? 1;

// Rank Check for Writing
if ($my_rank < $write_req) {
    echo "<div style='color:#e06c75; font-family:monospace; padding:10px; font-size:0.8rem;'>[READ ONLY FREQUENCY]</div>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 0. ENFORCE LOCK
    if ($is_locked && $my_rank < $lock_req) {
        // Redirect to self to show the HTML "LOCKED" banner defined below
        header("Location: chat_input.php"); 
        exit;
    }

    // 0.5 ENFORCE SLOW MODE (Global OR User Override)
    $actual_slow = $_SESSION['user_slow'] ?? $slow_sec;
    
    if ($actual_slow > 0 && $my_rank < 9) {
        // [FIX] Use Username for check (Safe for Guests & Users) to track specific identity
        $l_stmt = $pdo->prepare("SELECT created_at FROM chat_messages WHERE username = ? ORDER BY id DESC LIMIT 1");
        $l_stmt->execute([$_SESSION['username']]);
        if ($last_time = $l_stmt->fetchColumn()) {
            $diff = time() - strtotime($last_time);
            if ($diff < $actual_slow) { // [FIX] Compare against specific override ($actual_slow), not global default
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
// 3.5 COMMAND INTERCEPT (Game & Action Logic)
        if (str_starts_with($msg, '/')) {
            // /me Action
            if (preg_match('#^/me\s+(.+)$#is', $msg, $m)) {
                $msg = "[me]" . trim($m[1]) . "[/me]";
            }
            // /cointoss
            elseif (preg_match('#^/cointoss#i', $msg)) {
                $res = (random_int(0, 1) === 1) ? 'HEADS' : 'TAILS';
                $c_code = ($res === 'HEADS') ? '#e5c07b' : '#98c379'; 
                $msg = "[i]You flipped a coin and landed on [color={$c_code}][b]{$res}[/b][/color][/i]";
            }
            // /roll [max]
            elseif (preg_match('#^/roll(\s+(\d+))?#i', $msg, $m)) {
                $max = isset($m[2]) ? (int)$m[2] : 100;
                if ($max < 1) $max = 100;
                $val = random_int(1, $max);
                $msg = "[roll]Rolled [b]{$val}[/b] (1-{$max})[/roll]";
            }
            // /8ball [question]
            elseif (preg_match('#^/8ball\s+(.+)$#i', $msg, $m)) {
                $opt = ["It is certain.", "Without a doubt.", "Yes - definitely.", "Most likely.", "Ask again later.", "Better not tell you now.", "Don't count on it.", "My sources say no.", "Very doubtful."];
                $ans = $opt[array_rand($opt)];
                $msg = "[color=#56b6c2][b]🎱 8-BALL:[/b] " . htmlspecialchars($m[1]) . "[/color]\n[color=#ccc]>> " . $ans . "[/color]";
            }
            // /decide [opt1] [opt2]
            elseif (preg_match('#^/decide\s+(.+)$#i', $msg, $m)) {
                $opts = preg_split('/\s+/', trim($m[1]));
                $ch = $opts[array_rand($opts)];
                $msg = "[color=#e5c07b][b]⚖️ DECISION:[/b] I choose... " . htmlspecialchars($ch) . "[/color]";
            }
            // /reverse [text]
            elseif (preg_match('#^/reverse\s+(.+)$#i', $msg, $m)) {
                $rev = strrev(trim($m[1]));
                $msg = "&#8238;" . htmlspecialchars($rev); // RTL Override for visual reverse
            }
// /whisper <user> <msg> (INLINE VERSION)
            elseif (preg_match('#^/whisper\s+"?([^"\s]+)"?\s+(.+)$#is', $msg, $m)) {
                $t_user = trim($m[1]);
                $t_msg = trim($m[2]);
                $target_id = null;

                // Find Target (User or Guest)
                $stmt_u = $pdo->prepare("SELECT id FROM users WHERE username = ? UNION SELECT id FROM guest_tokens WHERE guest_username = ? AND status='active'");
                $stmt_u->execute([$t_user, $t_user]);
                $target_id = $stmt_u->fetchColumn();

                if ($target_id) {
                    $t_type = 'user';
                    // Check if it was actually a guest we found
                    $chk_g = $pdo->prepare("SELECT id FROM guest_tokens WHERE guest_username = ? AND status='active'");
                    $chk_g->execute([$t_user]);
                    if($chk_g->fetchColumn()) $t_type = 'guest';

                    $color = (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) ? ($_SESSION['guest_color'] ?? '#888888') : '#888888';
                    if (!isset($_SESSION['is_guest'])) {
                        $stmt_c = $pdo->prepare("SELECT chat_color FROM users WHERE id = ?");
                        $stmt_c->execute([$_SESSION['user_id']]);
                        $color = $stmt_c->fetchColumn() ?: '#888888';
                    }

                    $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, target_id, target_type, username, message, rank, color_hex, msg_type) VALUES (?, ?, ?, ?, ?, ?, ?, 'whisper')");
                    $stmt->execute([$_SESSION['user_id'] ?? $_SESSION['guest_token_id'], $target_id, $t_type, $_SESSION['username'], $t_msg, $_SESSION['rank'] ?? 0, $color]);
                }
                header("Location: chat_input.php"); exit;
            }
        }

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
        
        if (!$is_admin && preg_match('/(https?:\/\/[^\s]+|\b[a-z2-7]{56}(\.onion)?\b|\b[a-z2-7]{16}(\.onion)?\b)/i', $msg, $matches)) {
            $url = $matches[0];
            
            if (strpos($url, 'http') !== 0) {
                $url = 'http://' . $url;
            }

            $banned = false;
            
            $p_stmt = $pdo->query("SELECT pattern FROM banned_patterns");
            while($p = $p_stmt->fetch()) {
                if (stripos($url, $p['pattern']) !== false) $banned = true;
            }
            
            // REMOVED: History check. Banned Patterns list is now the SINGLE source of truth.
            
            if ($banned) {
                 header("Location: chat_input.php?status=banned_blocked"); exit;
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
            $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, channel_id, username, message, rank, color_hex, msg_type) VALUES (?, ?, ?, ?, ?, ?, 'normal')");
            $stmt->execute([$_SESSION['user_id'] ?? 0, $active_chan, $_SESSION['username'], $msg, $_SESSION['rank'] ?? 0, $color]);
            
            // --- AUTO PRUNE (Per Channel) ---
            $limit_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'max_chat_history'");
            $limit = (int)($limit_stmt->fetchColumn() ?: 150);
            
            $cnt = $pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();
            // Prune if total DB size gets too large (Global Prune to keep DB small)
            if ($cnt > ($limit * 2)) {
                 $pdo->exec("DELETE FROM chat_messages WHERE id <= (
                    SELECT id FROM (SELECT id FROM chat_messages ORDER BY id DESC LIMIT 1 OFFSET $limit) tmp
                )");
                // Signal Purge is aggressive but keeps it clean
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
    <link rel="stylesheet" href="style.css">
    <style>
        /* FLEXBOX LAYOUT: Ensures Notification Bar pushes the Form down naturally */
        html, body { 
            background: #0d0d0d; margin: 0; padding: 0; height: 100%; width: 100%;
            overflow: hidden; font-family: monospace; 
            display: flex; flex-direction: column; 
        }
        
        .info-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #1a1005 !important; 
            color: #e5c07b !important; 
            border-top: 1px dashed #e5c07b !important;
            padding: 5px 10px; 
            font-size: 0.65rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-sizing: border-box;
            z-index: 50;
        }.info-bar a { color: #fff; text-decoration: none; font-weight: bold; }

        .reply-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #1a1005; 
            color: #d19a66; 
            border-top: 1px dashed #d19a66;
            padding: 5px 10px; 
            font-size: 0.65rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-sizing: border-box;
            z-index: 40;
            font-weight: bold;
            font-family: monospace;
        }
        .reply-bar a { color: #e06c75; text-decoration: none; }

        /* Form fills remaining space */
        .info-bar a { color: #fff; text-decoration: none; font-weight: bold; }

        /* Form fills remaining space */
        form { 
            flex: 1; 
            display: flex; 
            width: 100%; 
            align-items: center; 
            padding: 5px; 
            padding-left: 45px; /* CRITICAL: Reserve space for the overlay button */
            box-sizing: border-box; 
        }
        input { flex: 1; background: #111; border: 1px solid #333; color: #eee; padding: 8px; font-family: inherit; outline: none; height: 30px; box-sizing: border-box; }
        input:focus { border-color: #555; background: #000; }
        button { background: #222; border: 1px solid #333; border-left: none; color: #6a9c6a; padding: 0 15px; cursor: pointer; font-weight: bold; font-size: 0.7rem; height: 30px; }
        button:hover { background: #6a9c6a; color: #000; }
    </style>
</head>
<body>
<?php if($is_locked && $my_rank < 9): ?>
        <div style="display:flex; align-items:center; justify-content:center; gap:10px; width:100%; height:100%; background:repeating-linear-gradient(45deg, #1a0505, #1a0505 10px, #2a0a0a 10px, #2a0a0a 20px); color:#e06c75; font-weight:bold; font-size:0.8rem; border:1px solid #e06c75; box-shadow:inset 0 0 20px #000;">
            <div style="font-size:1.5rem;">🔒</div>
            <div>
                <div>SIGNAL LOCKED</div>
                <div style="font-size:0.6rem; color:#888;">TRANSMISSION HALTED BY ADMIN</div>
            </div>
        </div>
    <?php elseif(isset($_SESSION['is_muted'])): ?>
        <?php
            $m_stmt = $pdo->prepare("SELECT mute_reason FROM users WHERE id = ?");
            $m_stmt->execute([$_SESSION['user_id']]);
            $m_reason = $m_stmt->fetchColumn() ?: 'Behavioral Adjustment';
        ?>
        <div style="display:flex; align-items:center; justify-content:center; gap:10px; width:100%; height:100%; background:#1a1005; color:#e5c07b; font-weight:bold; font-size:0.8rem; border-top:1px dashed #e5c07b;">
            <div style="font-size:1.2rem;">🔇</div>
            <div>
                <div>SILENCED</div>
                <div style="font-size:0.6rem; color:#886;">REASON: <?= htmlspecialchars($m_reason) ?></div>
            </div>
        </div>
    <?php else: ?>
        
        <form method="POST" autocomplete="off">
            <?php if($is_reply): ?>
                <input type="hidden" name="quote_data" value="<?= "\n[quote=" . htmlspecialchars($reply_user) . "]" . htmlspecialchars($reply_text) . "[/quote]" ?>">
            <?php endif; ?>
            
            <div style="display: flex; width: 100%;">
                <?php 
                $ph = "Type a message...";
                if ($slow_sec > 0 && $my_rank < 9) $ph = "Slow Mode: {$slow_sec}s delay active."; 
                ?>
                <input type="text" name="message" placeholder="<?= $ph ?>" autofocus required>
                <button type="submit">SEND</button>
            </div>
        </form>

        <?php if($link_pending): ?>
            <div class="info-bar">
                <span>[INFO] LINK DETAINED FOR MODERATION</span>
                <a href="chat_input.php">[ X ]</a>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['status']) && $_GET['status'] === 'banned_blocked'): ?>
            <div class="info-bar" style="background: #220505 !important; color: #e06c75 !important; border-color: #e06c75 !important;">
                <span>[WARN] LINK IS BLACKLISTED</span>
                <a href="chat_input.php">[ X ]</a>
            </div>
        <?php endif; ?>

        <?php if($is_reply): ?>
            <div class="reply-bar">
                <span>>> REPLYING TO: <span style="color:#fff;"><?= htmlspecialchars($reply_user) ?></span></span>
                <a href="chat_input.php">[ CANCEL ]</a>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</body>
</html>