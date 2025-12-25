<?php
// chat_stream.php (V10 - No-JS / Separate Alerts)
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('X-Accel-Buffering: no');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');

session_start();
require 'db_config.php';
require 'bbcode.php';
// KEY: Release session lock immediately
session_write_close();

if (!isset($_SESSION['fully_authenticated'])) die();

$my_rank = $_SESSION['rank'] ?? 1;
$last_id = 0;

// Initialize Signal Tracker
$stmt_sig = $pdo->query("SELECT MAX(id) FROM chat_signals");
$last_sig_id = $stmt_sig->fetchColumn() ?? 0;

// --- LOAD EMOJI PRESETS ---
global $pdo;
$emoji_presets = ['❤️','🔥','👍','💀']; 
try {
    $s_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='chat_emoji_presets'");
    $raw = $s_stmt->fetchColumn();
    if ($raw) $emoji_presets = explode(',', $raw);
} catch (Exception $e) {}

// HELPER: Vibrant Color Generator
function to_pastel($hex) {
    $hex = ltrim($hex, '#');
    if(strlen($hex)==3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2)); 
    $b = hexdec(substr($hex,4,2));
    
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

    // Hide duplicates using CSS
    echo "<style>
        div[id='{$base_id}'] { display: none !important; }
        div[id^='{$base_id}_'] { display: none !important; }
        div[id='{$new_dom_id}'] { display: {$display_mode} !important; }
    </style>";
    
    echo_message_v2($row, $rank, $new_dom_id, $presets);
}

// --- CORE DISPLAY FUNCTION ---
function echo_message_v2($row, $viewer_rank, $dom_id, $presets) {
    global $pdo; // REQUIRED for name lookup
    $my_id = $_SESSION['user_id'] ?? $_SESSION['guest_token_id'] ?? 0;
    
// --- WHISPER LOGIC & RENDERING ---
    if (($row['msg_type'] ?? '') === 'whisper') {
        $curr_type = (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) ? 'guest' : 'user';
        $my_real_id = (int)($curr_type === 'guest' ? ($_SESSION['guest_token_id'] ?? 0) : ($_SESSION['user_id'] ?? 0));
        
        $is_sender = ($row['user_id'] == $my_real_id && $row['username'] === ($_SESSION['username'] ?? ''));
        $is_target = ($row['target_id'] == $my_real_id && $row['target_type'] === $curr_type);
        
        if (!$is_sender && !$is_target && $viewer_rank < 9) return;

        // Determine Label
        if ($is_sender) {
            $t_name = "Unknown";
            try {
                if (($row['target_type'] ?? 'user') === 'user') {
                    $tn_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $tn_stmt->execute([$row['target_id']]);
                    $t_name = $tn_stmt->fetchColumn() ?: "Unknown";
                } else {
                    $tn_stmt = $pdo->prepare("SELECT guest_username FROM guest_tokens WHERE id = ?");
                    $tn_stmt->execute([$row['target_id']]);
                    $t_name = $tn_stmt->fetchColumn() ?: "Guest";
                }
            } catch (Exception $e) {}
            $w_label = "YOU WHISPERED to " . htmlspecialchars($t_name);
        } else {
            $w_label = "WHISPER FROM " . htmlspecialchars($row['username']);
        }

        // Delete Button Logic (Sender or Admin)
        $can_del = ($is_sender || $viewer_rank >= 9);
        $del_btn = $can_del ? "<a href='chat_action.php?del={$row['id']}' target='chat_input' style='color:#e06c75; font-weight:bold; text-decoration:none; margin-left:10px; float:right;' title='Delete Whisper'>[x]</a>" : "";

        $w_color = "#c678dd"; 
        $w_bg    = "#1a051a"; 
        $msg_body = parse_bbcode($row['message']);

        echo "<div class='msg-row' id='$dom_id' style='order: {$row['id']}; background: $w_bg; border-left: 3px solid $w_color; opacity: 0.95; padding-left:5px;'>
                <div class='col-time'>".date('H:i', strtotime($row['created_at']))."</div>
                <div style='flex-grow:1; padding-left:10px; color:$w_color; font-size:0.75rem; word-break: break-word;'>
                    <strong>[$w_label]</strong>: <span style='color:#e0e0e0;'>$msg_body</span>
                    $del_btn
                </div>
              </div>";
        return; 
    }
    
    if (($row['msg_type'] ?? 'normal') === 'system' || ($row['msg_type'] ?? 'normal') === 'broadcast') {
        $is_broadcast = ($row['msg_type'] === 'broadcast');
        
        if (($row['msg_type'] ?? 'normal') === 'system' || ($row['msg_type'] ?? 'normal') === 'broadcast') {
        $is_broadcast = ($row['msg_type'] === 'broadcast');
        
        if ($is_broadcast) {
            $c_border = (isset($row['color_hex']) && strlen($row['color_hex']) >= 4) ? $row['color_hex'] : '#333333';
            $rgb = sscanf($c_border, "#%02x%02x%02x");
            $c_bg = (isset($rgb[0]) && $rgb[0] !== null) ? "rgba({$rgb[0]}, {$rgb[1]}, {$rgb[2]}, 0.1)" : "#111";
            $c_text = $c_border;
            $label = $row['username'] ?? ''; 
        } else {
            $c_border = "#e06c75"; $c_bg = "#220505"; $c_text = "#e06c75"; 
            if (strpos($row['message'], '[INFO]') !== false) { $c_border = "#61afef"; $c_bg = "#051020"; $c_text = "#61afef"; } 
            elseif (strpos($row['message'], '[SUCCESS]') !== false) { $c_border = "#98c379"; $c_bg = "#051505"; $c_text = "#98c379"; } 
            elseif (strpos($row['message'], '[CRITICAL]') !== false) { $c_border = "#c678dd"; $c_bg = "#1a051a"; $c_text = "#c678dd"; } 
            elseif (strpos($row['message'], '[MAINT]') !== false) { $c_border = "#e5c07b"; $c_bg = "#1a1005"; $c_text = "#e5c07b"; }
            
            // If the username field in DB is literally 'SYSTEM', and it's a 'system' type, 
            // you can choose to hide it here by setting $label = '';
            $label = ($row['username'] === 'SYSTEM') ? '' : $row['username'];
        }
        
        $label_html = !empty($label) ? "<strong style='background:$c_border; color:#000; padding:2px 6px; font-size:0.7rem; flex-shrink:0;'>[$label]</strong>" : "";
        $alert_style = "border: 1px solid $c_border; background: $c_bg; color: $c_text;";
        
        $can_del = ($viewer_rank >= 9); // Specifically for system messages, Rank 9+ only
        $del_btn = $can_del ? "<a href='chat_action.php?del={$row['id']}' target='chat_input' class='sys-del-btn' title='Remove Broadcast'>[x]</a>" : "";

        echo "<div class='msg-row sys-msg' id='$dom_id' style='$alert_style display:flex; align-items:center; justify-content:center; gap:15px; order: {$row['id']}; position:relative;'>
                $label_html
                <div style='font-weight:bold; letter-spacing:0.5px; font-size:0.8rem;'>
                    ".parse_bbcode($row['message'])."
                </div>
                $del_btn
              </div>";
        return;
    }
        return;
    }

$raw_style = $row['color_hex'] ?? '#888888';
    $msg_color = '#ccc'; 

    // SECURITY: Prevent deanonymization via CSS external resources
    $raw_style = str_ireplace(['url(', 'http:', 'https:', 'ftp:', 'data:', 'expression'], '', $raw_style);

    // LOGIC: Determine Content + Style
    $inner_html = "";
    $wrapper_style = "";

    // CHECK: Is it a BBCode Template? (Contains {u} or brackets)
    if (strpos($raw_style, '{u}') !== false || (str_starts_with(trim($raw_style), '[') && str_ends_with(trim($raw_style), ']'))) {
        
        $processed_name = str_replace('{u}', $row['username'], $raw_style);
        if (strpos($raw_style, '{u}') === false) $processed_name = $raw_style . $row['username'];
        
        $inner_html = parse_bbcode($processed_name);
        
        // INTELLIGENT COLOR EXTRACTION
        // If the username has a [color=#hex] tag, we extract it and apply a pastel version to the message text.
        if (preg_match('/\[color=(#[a-f0-9]{3,6})\]/i', $raw_style, $matches)) {
            $msg_color = to_pastel($matches[1]);
        } else {
            // No specific color tag found (e.g. just [b] or [glitch]), default to soft grey
            $msg_color = '#d0d0d0'; 
        }

    } else {
        // CHECK: Is it CSS or Hex?
// CHECK: Is it CSS or Hex?
    if (strpos($raw_style, ';') !== false || strpos($raw_style, ':') !== false) {
        // [FIX] Apply CSS wrapper but ALSO allow BBCode parsing inside the username
        $wrapper_style = $raw_style;
        $inner_html = (strpos($raw_style, '[') !== false) ? parse_bbcode(str_replace('{u}', $row['username'], $raw_style)) : $row['username'];
        
        // Extract color for message text if present in the CSS or BBCode
        if (preg_match('/color:\s*(#[a-f0-9]{3,6})/i', $raw_style, $m)) $msg_color = to_pastel($m[1]);
        elseif (preg_match('/\[color=(#[a-f0-9]{3,6})\]/i', $raw_style, $m)) $msg_color = to_pastel($m[1]);
        else $msg_color = '#d0d0d0';
    } else {
            // It's a simple Hex code (e.g. "#ff0000")
            $wrapper_style = "color: " . $raw_style;
            $inner_html = $row['username'];
            $msg_color = to_pastel($raw_style);
        }
    }

    // [FIX] Strip the CSS properties from the inner HTML if they were accidentally parsed as text
    $clean_inner = (strpos($raw_style, ';') !== false) ? str_replace($raw_style, '', $inner_html) : $inner_html;
    if(empty($clean_inner)) $clean_inner = $row['username'];

    // FINAL OUTPUT
    $user_display_html = "<div class='col-user' style='$wrapper_style'>$clean_inner</div>";
    
    // --- STRAY BRACKET REMOVED HERE ---

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
        $user_display_html
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
        /* FIXED: Allow body to expand beyond 100% for scrolling */
        html { height: 100%; margin: 0; padding: 0; }
        body { 
            min-height: 100%; height: auto; margin: 0; padding: 20px;
            background: transparent; 
            overflow-y: auto; /* Enable native scrollbar */
            font-family: monospace; 
            display: flex; 
            /* REVERSE: Newest items (Higher Order/ID) go to the TOP */
            flex-direction: column-reverse; 
            justify-content: flex-end;      
        }
        body { font-size: 0.75rem; } 
        
        .msg-row { 
            width: 100%; padding: 2px 0; border-bottom: 1px solid #111; 
            display: flex; gap: 8px; align-items: flex-start; flex-shrink: 0; 
        }
        .sys-msg { padding: 5px; margin: 2px 0; font-size: 0.7rem; }

        .col-time { width: 35px; flex-shrink: 0; color: #444; text-align: right; margin-top: 1px; }
        .col-user { flex-shrink: 0; font-weight: bold; margin-top: 1px; }
        .col-text { flex-grow: 1; word-break: break-word; overflow-wrap: break-word; line-height: 1.3; color: #ccc; min-width: 0; }
        .col-act { flex-shrink: 0; white-space: nowrap; margin-left: 5px; }

        .action-link { color: #555; text-decoration: none; cursor: pointer; }
        .action-link:hover { color: #6a9c6a; }
        .del-btn { color: #444; font-weight: bold; text-decoration: none; }
        .del-btn:hover { color: #e06c75; }

        details.react-box { display: inline-block; position: relative; }
        details.react-box summary { list-style: none; cursor: pointer; color: #555; }
        details.react-box summary:hover { color: #e5c07b; }
        
        .react-popup { 
            position: absolute; right: 0; top: 100%; margin-top: 5px;
            background: #161616; border: 1px solid #333; padding: 8px; 
            z-index: 20; min-width: 150px; display: flex; flex-direction: column; gap: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.8);
        }
        .react-presets a { filter: grayscale(100%); transition: 0.2s; }
        .react-presets a:hover { filter: grayscale(0%); transform: scale(1.2); }
    </style>
</head>
<body>
<?php
try {
    // --- INITIAL HISTORY LOAD ---
    $cid = $_SESSION['active_channel'] ?? 1;
    // Filter by Channel ID
    $stmt = $pdo->prepare("SELECT * FROM (SELECT * FROM chat_messages WHERE channel_id = ? ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
    $stmt->execute([$cid]);
    $history = $stmt->fetchAll();
    foreach($history as $msg) {
        $last_id = $msg['id'];
        echo_message_v2($msg, $my_rank, "msg_".$msg['id'], $emoji_presets);
    }
    flush();

    // --- STATE TRACKING ---
    $heartbeat = 0; 
    $last_active_update = 0;
    
    // Notification State
    $last_pm_count = -1;
    $last_pm_alert_id = null;
    $last_link_count = -1;
    $last_link_alert_id = null;
    
    // Pin State
    $current_pin_hash = '';
    $last_pin_check = 0;

    while (true) {
        $heartbeat++;
        $now = time();
        
        // -1. CHECK PINNED MESSAGE (Every ~5s)
        if ($now - $last_pin_check >= 5) {
            $last_pin_check = $now;
            // Initialize tracker if not set in this scope
            $last_pin_dom_id = $last_pin_dom_id ?? null; 

            try {
                // Fetch Pin & Styles for Active Channel
                $cid = $_SESSION['active_channel'] ?? 1;
                $p_stmt = $pdo->prepare("SELECT pinned_msg, pin_style, pin_custom_color, pin_custom_emoji FROM chat_channels WHERE id = ?");
                $p_stmt->execute([$cid]);
                $chan_data = $p_stmt->fetch();

                $pin_msg = trim($chan_data['pinned_msg'] ?? '');
                $pin_style = $chan_data['pin_style'] ?? 'INFO';
                $custom_color = $chan_data['pin_custom_color'] ?? '#6a9c6a'; 
                $custom_emoji = $chan_data['pin_custom_emoji'] ?? ''; 
                
                $pin_hash = md5($pin_msg . $pin_style . $cid); // Hash includes CID to refresh on switch
                if ($pin_hash !== $current_pin_hash) {
                    $current_pin_hash = $pin_hash;

                    // 1. Clean up OLD pin using CSS (No JS)
                    if ($last_pin_dom_id) {
                        echo "<style>#{$last_pin_dom_id} { display: none !important; }</style>";
                    }

                    // 2. Output NEW Pin (or nothing if empty)
                    if ($pin_msg !== '') {
                        $parsed_pin = parse_bbcode($pin_msg);
                        
                        $c_bg = "#1a1005"; $c_text = "#e5c07b"; $c_border = "#e5c07b"; $label = "[NOTICE]";
                        if ($pin_style === 'WARN') { $c_bg = "#220505"; $c_text = "#e06c75"; $c_border = "#e06c75"; $label = "[WARNING]"; }
                        elseif ($pin_style === 'CRIT') { $c_bg = "#1a051a"; $c_text = "#c678dd"; $c_border = "#c678dd"; $label = "[CRITICAL]"; }
                        elseif ($pin_style === 'SUCCESS') { $c_bg = "#051505"; $c_text = "#98c379"; $c_border = "#98c379"; $label = "[UPDATE]"; }
                        elseif ($pin_style === 'INFO') { $c_bg = "#051020"; $c_text = "#61afef"; $c_border = "#61afef"; $label = "[INFO]"; }
                        elseif ($pin_style === 'CUSTOM') { $c_bg = "#0d0d0d"; $c_text = $custom_color; $c_border = $custom_color; $label = $custom_emoji; }
                        elseif ($pin_style === 'NONE') { $c_bg = "#0d0d0d"; $c_text = $custom_color; $c_border = $custom_color; $label = ""; }

                        $pid = "pin_" . time() . "_" . rand(100,999);
                        $last_pin_dom_id = $pid;
                        
                        $label_html = ($label !== "") ? "<strong style='background:$c_border; color:#000; padding:2px 6px; border-radius:2px; flex-shrink:0;'>$label</strong>" : "";

                        echo "<div id=\"$pid\" style=\"
                                position: sticky; 
                                top: 0; 
                                order: 2147483647; 
                                z-index: 9000; 
                                margin: -20px -20px 20px -20px;
                                background: $c_bg; 
                                border-bottom: 1px solid $c_border; 
                                color: $c_text; 
                                padding: 10px 15px; 
                                font-size: 0.75rem; 
                                box-shadow: 0 5px 15px rgba(0,0,0,0.5); 
                                display: flex; flex-direction: column; align-items: stretch; gap: 5px;
                              \">
                                <div style=\"display: flex; align-items: center; gap: 10px; width: 100%;\">
                                    $label_html 
                                    <span style=\"flex-grow: 1; width: 100%;\">$parsed_pin</span>
                                </div>
                              </div>";
                    } else {
                        $last_pin_dom_id = null;
                    }
                }
            } catch (Exception $e) {}
        }

        // 0. UPDATE PRESENCE (Every 60s)
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

        // 1. INSTANT SECURITY HEARTBEAT
        if ($heartbeat % 3 === 0) { 
             $kill_stream = false;
             if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
                 $chk = $pdo->prepare("SELECT status FROM guest_tokens WHERE id = ?");
                 $chk->execute([$_SESSION['guest_token_id'] ?? 0]);
                 if ($chk->fetchColumn() !== 'active') $kill_stream = true;
             } else {
                 $chk = $pdo->prepare("SELECT is_banned, force_logout FROM users WHERE id = ?");
                 $chk->execute([$_SESSION['user_id'] ?? 0]);
                 $st = $chk->fetch();
                 if ($st && ($st['is_banned'] == 1 || $st['force_logout'] == 1)) $kill_stream = true;
             }

             if ($kill_stream) {
                 echo "<style>html, body { background: #000 !important; overflow:hidden; } .msg-row, .sys-msg, #pin_banner { display: none !important; }</style>";
                 echo "<div style='position:fixed; top:0; left:0; width:100%; height:100%; background:#000; display:flex; align-items:center; justify-content:center; flex-direction:column; z-index:99999;'>
                        <h1 style='color:#e06c75; margin-bottom:20px; font-size:1.5rem;'>SIGNAL LOST</h1>
                        <a href='logout.php' target='_top' style='color:#fff; border:1px solid #e06c75; padding:10px 20px; text-decoration:none; font-weight:bold; background:#1a0505;'>[ EXIT ]</a>
                       </div>";
                 echo str_repeat(" ", 1024); flush(); die();
             }
        }

        // 2. Reset Check
        $check_reset = $pdo->query("SELECT MAX(id) FROM chat_messages")->fetchColumn();
        if ($check_reset < $last_id) $last_id = 0;

        // 3. New Messages
        $cid = $_SESSION['active_channel'] ?? 1;
        $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE id > ? AND channel_id = ? ORDER BY id ASC");
        $stmt->execute([$last_id, $cid]);
        $new = $stmt->fetchAll();
        foreach($new as $msg) {
            $last_id = $msg['id'];
            render_update($msg, $my_rank, $emoji_presets);
        }
        // NOTE: No JS Scroll here. CSS 'column-reverse' handles it.

        // 4. Signals
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

        // 5. SEPARATE NOTIFICATIONS (PMs & Links)
        if ($heartbeat % 5 === 0) {
            
            // --- A. CHECK PRIVATE MESSAGES ---
            $chk_pm = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0");
            $chk_pm->execute([$_SESSION['user_id']]);
            $pm_count = (int)$chk_pm->fetchColumn();

            if ($pm_count !== $last_pm_count) {
                // Clear old
                if ($last_pm_alert_id) { echo "<style>#{$last_pm_alert_id} { display: none !important; }</style>"; $last_pm_alert_id = null; }
                
                // Show New (Bottom 0px)
                if ($pm_count > 0) {
                    $new_id = "pm_alert_" . time();
                    $chk_id = "chk_" . $new_id;
                    $last_pm_alert_id = $new_id;
                    
                    echo "
                    <style>
                        #$new_id {
                            position: fixed; bottom: 0; left: 0; width: 100%;
                            background: var(--alert-bg, #1a0505); 
                            color: var(--alert-text, #e06c75); 
                            border-top: 1px dashed var(--alert-border, #e06c75); 
                            padding: 8px 15px; font-family: monospace; font-size: 0.7rem;
                            display: flex; justify-content: space-between; align-items: center;
                            z-index: 99999; box-sizing: border-box;
                        }
                        #$new_id a, #$new_id label { color: inherit; text-decoration: none; font-weight: bold; cursor: pointer; margin-left: 10px; }
                        #$chk_id { display: none; }
                        #$chk_id:checked + #$new_id { display: none !important; }
                    </style>
                    <input type='checkbox' id='$chk_id'>
                    <div id='$new_id'>
                        <span>[MSG] <strong>$pm_count</strong> New Private Message(s).</span>
                        <div><a href='pm.php' target='_blank'>[ OPEN INBOX ]</a><label for='$chk_id'>[ X ]</label></div>
                    </div>";
                }
                $last_pm_count = $pm_count;
            }

            // --- B. CHECK PENDING LINKS (Admins Only) ---
            if ($my_rank >= 9) {
                $link_count = (int)$pdo->query("SELECT COUNT(*) FROM shared_links WHERE status='pending'")->fetchColumn();
                
                if ($link_count !== $last_link_count) {
                    // Clear old
                    if ($last_link_alert_id) { echo "<style>#{$last_link_alert_id} { display: none !important; }</style>"; $last_link_alert_id = null; }
                    
                    // Show New (Bottom 36px to stack above PM alert)
                    if ($link_count > 0) {
                        $new_id = "lnk_alert_" . time();
                        $chk_id = "chk_" . $new_id;
                        $last_link_alert_id = $new_id;
                        
                        echo "
                        <style>
                            #$new_id {
                                position: fixed; bottom: 0px; left: 0; width: 100%;
                                background: #05101a; color: #56b6c2; 
                                border-top: 1px dashed #56b6c2; 
                                padding: 8px 15px; font-family: monospace; font-size: 0.7rem;
                                display: flex; justify-content: space-between; align-items: center;
                                z-index: 99998; box-sizing: border-box;
                            }
                            #$new_id a, #$new_id label { color: inherit; text-decoration: none; font-weight: bold; cursor: pointer; margin-left: 10px; }
                            #$chk_id { display: none; }
                            #$chk_id:checked + #$new_id { display: none !important; }
                        </style>
                        <input type='checkbox' id='$chk_id'>
                        <div id='$new_id'>
                            <span>[MOD] <strong>$link_count</strong> Link(s) Awaiting Approval.</span>
                            <div><a href='admin_dash.php?view=links' target='_blank'>[ REVIEW ]</a><label for='$chk_id'>[ X ]</label></div>
                        </div>";
                    }
                    $last_link_count = $link_count;
                }
            }
        } // End Notification Check

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