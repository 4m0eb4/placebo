<?php
// chat_stream.php (V9 - Stable Connection)
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
// Safely clear all buffers to prevent connection drops
while (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('X-Accel-Buffering: no');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');

session_start();
require 'db_config.php';
require 'bbcode.php';
session_write_close();

if (!isset($_SESSION['fully_authenticated'])) die();

$my_rank = $_SESSION['rank'] ?? 1;
$last_id = 0;

// Initialize Signal Tracker
$stmt_sig = $pdo->query("SELECT MAX(id) FROM chat_signals");
$last_sig_id = $stmt_sig->fetchColumn() ?? 0;

// --- LOAD EMOJI PRESETS ---
global $pdo;
$emoji_presets = ['❤️','🔥','👍','💀']; // Default Fallback
try {
    $s_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='chat_emoji_presets'");
    $raw = $s_stmt->fetchColumn();
    if ($raw) $emoji_presets = explode(',', $raw);
} catch (Exception $e) {}

// HELPER: Vibrant Color Generator (Saturated)
function to_pastel($hex) {
    $hex = ltrim($hex, '#');
    if(strlen($hex)==3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    
    // Parse (Fixed Green Index)
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2)); 
    $b = hexdec(substr($hex,4,2));
    
    // Mix: 80% Color + 20% White (More Saturated)
    $r = (int)($r * 0.8 + 255 * 0.2);
    $g = (int)($g * 0.8 + 255 * 0.2);
    $b = (int)($b * 0.8 + 255 * 0.2);
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// --- RENDER FUNCTION ---
function render_update($row, $rank, $presets) {
    $unique_suffix = "_" . bin2hex(random_bytes(3)); 
    $base_id = "msg_" . $row['id'];
    $new_dom_id = $base_id . $unique_suffix;

    $is_system = ($row['msg_type'] ?? 'normal') === 'system';
    $display_mode = $is_system ? 'block' : 'flex';

    echo "<style>
        div[id='{$base_id}'] { display: none !important; }
        div[id^='{$base_id}_'] { display: none !important; }
        div[id='{$new_dom_id}'] { display: {$display_mode} !important; }
    </style>";
    
    echo_message_v2($row, $rank, $new_dom_id, $presets);
}

// --- CORE DISPLAY FUNCTION ---
function echo_message_v2($row, $viewer_rank, $dom_id, $presets) {
    
    if (($row['msg_type'] ?? 'normal') === 'system') {
        $c_border = "#e06c75"; $c_bg = "#220505"; $c_text = "#e06c75"; 
        
        if (strpos($row['message'], '[INFO]') !== false) { $c_border = "#61afef"; $c_bg = "#051020"; $c_text = "#61afef"; } 
        elseif (strpos($row['message'], '[SUCCESS]') !== false) { $c_border = "#98c379"; $c_bg = "#051505"; $c_text = "#98c379"; } 
        elseif (strpos($row['message'], '[CRITICAL]') !== false) { $c_border = "#c678dd"; $c_bg = "#1a051a"; $c_text = "#c678dd"; } 
        elseif (strpos($row['message'], '[MAINT]') !== false) { $c_border = "#e5c07b"; $c_bg = "#1a1005"; $c_text = "#e5c07b"; }
        
        $alert_style = "border: 1px solid $c_border; background: $c_bg; color: $c_text;";
        echo "<div class='msg-row sys-msg' id='$dom_id' style='$alert_style display:block; order: {$row['id']};'>
                <div style='text-align:center; width:100%; font-weight:bold; letter-spacing:1px; font-size:0.8rem;'>
                    ".parse_bbcode($row['message'])."
                </div>
              </div>";
        return;
    }

    $u_color = $row['color_hex'] ?? '#888888';
    $msg_color = to_pastel($u_color);
    $time = date('H:i', strtotime($row['created_at']));
    $text = parse_bbcode($row['message']);
    
    $clean_text = trim(strip_tags(preg_replace('/\[quote(?:=.*?)?\].*?\[\/quote\]/s', '', $row['message'])));
    $reply_url = "chat_input.php?reply_user=" . urlencode($row['username']) . "&reply_text=" . urlencode($clean_text);
    
    $react_links = "";
    $base_act = "chat_action.php?react=1&id=" . $row['id'] . "&emoji=";
    foreach($presets as $em) {
        $em = trim($em);
        $react_links .= "<a href='{$base_act}" . urlencode($em) . "' target='chat_input' style='text-decoration:none; font-size:1.1rem;'>$em</a> ";
    }

    global $pdo; 
    $react_display = "";
    try {
        $r_stmt = $pdo->prepare("SELECT emoji, COUNT(*) as c FROM chat_reactions WHERE message_id = ? GROUP BY emoji");
        $r_stmt->execute([$row['id']]);
        $reacts = $r_stmt->fetchAll();
        if($reacts) {
            $react_display = "<span style='margin-left:8px; display:inline-block;'>";
            foreach($reacts as $r) {
                $quick_act = "chat_action.php?react=1&id=" . $row['id'] . "&emoji=" . urlencode($r['emoji']);
                $react_display .= "
                <a href='$quick_act' target='chat_input' style='text-decoration:none; margin-right:4px;'>
                    <span style='background:#111; border:1px solid #333; padding:1px 5px; font-size:0.7rem; border-radius:3px; color:#aaa; cursor:pointer;'>
                        {$r['emoji']} <span style='color:#666; font-size:0.6rem; margin-left:2px;'>{$r['c']}</span>
                    </span>
                </a>";
            }
            $react_display .= "</span>";
        }
    } catch (Exception $e) {}

    $is_mine = ($row['username'] === $_SESSION['username']);
    $can_del = ($viewer_rank >= 5 || $is_mine);
    $del_btn = $can_del ? "<a href='chat_action.php?del={$row['id']}' target='chat_input' class='del-btn'>[x]</a>" : "";

    echo "
    <div class='msg-row' id='$dom_id' style='order: {$row['id']};'>
        <div class='col-time'>$time</div>
        <div class='col-user' style='color:$u_color;'>{$row['username']}</div>
        <div class='col-text' style='color:$msg_color;'>
            $text$react_display
        </div>
        <div class='col-act'>
            <a href='$reply_url' target='chat_input' class='action-link'>[reply]</a>
            <details class='react-box' style='display:inline-block;'>
                <summary style='opacity:0.5; cursor:pointer; list-style:none;'>[+]</summary>
                <div class='react-popup'>
                    <div class='react-presets' style='display:flex; gap:8px; margin-bottom:5px; border-bottom:1px solid #333; padding-bottom:5px;'>
                        $react_links
                    </div>
                    <form action='chat_action.php' target='chat_input' method='GET' style='display:flex; gap:5px;'>
                        <input type='hidden' name='react' value='1'>
                        <input type='hidden' name='id' value='{$row['id']}'>
                        <input type='text' name='emoji' placeholder='Custom...' required maxlength='10' 
                               style='width:60px; background:#000; border:1px solid #333; color:#fff; font-size:0.7rem; padding:4px;'>
                        <button type='submit' style='background:#222; color:#6a9c6a; border:1px solid #333; cursor:pointer;'>&gt;</button>
                    </form>
                </div>
            </details>
            $del_btn
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body { 
            background: transparent; padding: 20px; overflow-y: scroll; 
            font-family: monospace; display: flex; flex-direction: column-reverse; 
            justify-content: flex-end;      
        }
        body { font-size: 0.75rem; } /* Global Size Reduction */
        
        .msg-row { 
            width: 100%; padding: 2px 0; border-bottom: 1px solid #111; 
            animation: slideDown 0.2s ease-out; display: flex; gap: 8px; 
            align-items: flex-start; flex-shrink: 0; 
        }
        .sys-msg { padding: 5px; margin: 2px 0; font-size: 0.7rem; }

        /* Col 1: Time (Aligned with Actions) */
        .col-time { width: 35px; flex-shrink: 0; color: #444; text-align: right; margin-top: 1px; }
        
        /* Col 2: Username */
        .col-user { flex-shrink: 0; font-weight: bold; margin-top: 1px; }

        /* Col 3: Message (Wraps BEFORE hitting Actions) */
        .col-text { 
            flex-grow: 1; word-break: break-word; overflow-wrap: break-word; 
            line-height: 1.3; color: #ccc; min-width: 0;
        }

        /* Col 4: Actions (Fixed Right) */
        .col-act { flex-shrink: 0; white-space: nowrap; margin-left: 5px; }
        .msg-actions { float: right; margin-left: 10px; font-size: 0.7rem; display: inline-flex; gap: 8px; align-items: center; }
        .action-link { color: #555; text-decoration: none; cursor: pointer; }
        .action-link:hover { color: #6a9c6a; }
        .del-btn { color: #444; font-weight: bold; text-decoration: none; }
        .del-btn:hover { color: #e06c75; }

        details.react-box { display: inline-block; position: relative; }
        details.react-box summary { list-style: none; cursor: pointer; color: #555; }
        details.react-box summary::-webkit-details-marker { display: none; }
        details.react-box summary:hover { color: #e5c07b; }
        
        .react-popup { 
            position: absolute; right: 0; top: 100%; margin-top: 5px;
            background: #161616; border: 1px solid #333; padding: 8px; 
            z-index: 20; min-width: 150px; display: flex; flex-direction: column; gap: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.8);
        }
        .react-presets a { filter: grayscale(100%); transition: 0.2s; }
        .react-presets a:hover { filter: grayscale(0%); transform: scale(1.2); }

        @keyframes slideDown { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>
<?php
try {
    // --- INITIAL HISTORY LOAD ---
    $stmt = $pdo->query("SELECT * FROM (SELECT * FROM chat_messages ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
    $history = $stmt->fetchAll();
    foreach($history as $msg) {
        $last_id = $msg['id'];
        echo_message_v2($msg, $my_rank, "msg_".$msg['id'], $emoji_presets);
    }
    flush();

    // --- MAIN STREAM LOOP ---
    $heartbeat = 0; 
    $last_active_update = 0;

while (true) {
        $heartbeat++;
        $now = time();
// 0a. UPDATE PRESENCE (Every 60s)
        if ($now - $last_active_update >= 60) {
            try {
                if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
                    $pdo->prepare("UPDATE guest_tokens SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['guest_token_id']]);
                } elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
                    $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
                }
            } catch (Exception $e) { }
            $last_active_update = $now;
        }

        // --- 0b. INSTANT SECURITY HEARTBEAT ---
        if ($heartbeat % 3 === 0) { 
             $kill_stream = false;

             if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
                 $chk = $pdo->prepare("SELECT status FROM guest_tokens WHERE id = ?");
                 $chk->execute([$_SESSION['guest_token_id'] ?? 0]);
                 $st = $chk->fetchColumn();
                 if ($st !== 'active') $kill_stream = true;
             } else {
                 $chk = $pdo->prepare("SELECT is_banned, force_logout FROM users WHERE id = ?");
                 $chk->execute([$_SESSION['user_id'] ?? 0]);
                 $st = $chk->fetch();
                 if ($st && ($st['is_banned'] == 1 || $st['force_logout'] == 1)) $kill_stream = true;
             }

if ($kill_stream) {
                 // NUCLEAR OPTION: CSS Override to instantly black out the frame
                 echo "<style>
                    html, body { 
                        background: #000 !important; height: 100% !important; width: 100% !important; 
                        margin: 0 !important; overflow: hidden !important; 
                    }
                    /* Hide EVERYTHING else */
                    .msg-row, .sys-msg, .chat-container { display: none !important; }
                    
                    /* Force Overlay */
                    .crash-overlay {
                        position: fixed !important; top: 0; left: 0; width: 100vw; height: 100vh;
                        background: #000; z-index: 999999;
                        display: flex; flex-direction: column; align-items: center; justify-content: center;
                    }
                    .crash-box {
                        text-align: center; border: 2px solid #e06c75; padding: 30px; background: #1a0505;
                        width: 80%; max-width: 400px;
                        box-shadow: 0 0 50px rgba(224, 108, 117, 0.2);
                    }
                    h1 { color: #e06c75; margin: 0 0 15px 0; font-size: 1.5rem; text-transform: uppercase; animation: blink 0.5s infinite alternate; }
                    p { color: #888; font-size: 0.8rem; margin-bottom: 25px; }
                    a.btn { 
                        display: block; border: 1px solid #e06c75; color: #fff; padding: 15px; 
                        text-decoration: none; background: #e06c75; font-weight: bold; 
                        letter-spacing: 2px; cursor: pointer; transition: all 0.2s;
                    }
                    a.btn:hover { background: #fff; color: #000; border-color: #fff; }
                    @keyframes blink { from { opacity: 1; } to { opacity: 0.3; } }
                    
                    /* Hide underlying stream */
                    .msg-row, .sys-msg { display: none !important; }
                 </style>";
                 
                 echo "<div class='crash-overlay'>
                        <div class='crash-box'>
                            <h1>CONNECTION SEVERED</h1>
                            <p>UPLINK TERMINATED BY HOST</p>
                            <a href='terminated.php' target='_top' class='btn'>[ EXIT SESSION ]</a>
                        </div>
                       </div>";
                 
                 echo str_repeat(" ", 1024); 
                 flush();
                 die();
             }
        }

        // 1. Reset Check
        $check_reset = $pdo->query("SELECT MAX(id) FROM chat_messages")->fetchColumn();
        if ($check_reset < $last_id) $last_id = 0;

        // 2. New Messages
        $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE id > ? ORDER BY id ASC");
        $stmt->execute([$last_id]);
        $new = $stmt->fetchAll();
        foreach($new as $msg) {
            $last_id = $msg['id'];
            render_update($msg, $my_rank, $emoji_presets);
        }

        // 3. Signals
        $s_stmt = $pdo->prepare("SELECT * FROM chat_signals WHERE id > ? ORDER BY id ASC");
        $s_stmt->execute([$last_sig_id]);
        $signals = $s_stmt->fetchAll();
        
        foreach($signals as $sig) {
            $last_sig_id = $sig['id'];
            $target = (int)$sig['signal_val'];

            if ($sig['signal_type'] === 'DELETE') {
                echo "<style>div[id^='msg_{$target}'] { display: none !important; }</style>";
            }
            if ($sig['signal_type'] === 'PURGE') {
                echo "<style>div[id^='msg_'] { display: none !important; }</style>";
            }
            if ($sig['signal_type'] === 'REACT') {
                $stmt_r = $pdo->prepare("SELECT * FROM chat_messages WHERE id = ?");
                $stmt_r->execute([$target]);
                $row_r = $stmt_r->fetch();
                if ($row_r) render_update($row_r, $my_rank, $emoji_presets);
            }
        }
// 4. LIVE PM ALERTS (Bottom Bar Style)
        static $prev_alert_id = null;
        
        if ($heartbeat % 5 === 0) {
            // Clear previous alert if exists
            if ($prev_alert_id) {
                echo "<style>#{$prev_alert_id} { display: none !important; }</style>";
                $prev_alert_id = null;
            }

            $chk_pm = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0");
            $chk_pm->execute([$_SESSION['user_id']]);
            $unread = $chk_pm->fetchColumn();

            if ($unread > 0) {
                $new_id = "pm_alert_" . time();
                $prev_alert_id = $new_id;
                
                // EXACT COPY OF "LINK DETAINED" STYLE
                // Fixed to bottom of stream window
                echo "
                <style>
                    #$new_id {
                        position: fixed; bottom: 0; left: 0; width: 100%;
                        background: #1a1005; 
                        color: #e5c07b; 
                        border-top: 1px dashed #e5c07b; 
                        padding: 8px 15px; 
                        font-family: monospace; font-size: 0.7rem;
                        display: flex; justify-content: space-between; align-items: center;
                        z-index: 99999; box-sizing: border-box;
                    }
                    #$new_id a { color: inherit; text-decoration: underline; font-weight: bold; cursor: pointer; }
                </style>
                <div id='$new_id'>
                    <span>[INFO] <strong>$unread Encrypted Signal(s)</strong> detected.</span>
                    <a href='pm.php' target='_blank'>[ DECRYPT ]</a>
                </div>";
            }
        }

        echo " "; flush();
        sleep(1);
        if (connection_aborted()) break;
    }
} catch (Exception $e) { 
    echo "DB_ERROR: " . htmlspecialchars($e->getMessage()); 
}
?>
</body>
</html>