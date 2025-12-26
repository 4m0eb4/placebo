<?php
// pm_stream.php - Streaming Engine for PMs (No-JS / Tor Safe)
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Nginx specific: Disable buffering
session_start();
require 'db_config.php';
require 'bbcode.php';

// KEY FIX: Close session lock immediately so other scripts (input) can run
session_write_close();

if (!isset($_SESSION['fully_authenticated']) || !isset($_GET['to'])) die();

$my_id = $_SESSION['user_id'];
$target_id = (int)$_GET['to'];
$last_id = 0;

// Fetch Target Name for display
$stmt_u = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt_u->execute([$target_id]);
$t_name = $stmt_u->fetchColumn();

// RENDER HELPER
function render_pm($row, $my_id, $target_name) {
    $is_me = ($row['sender_id'] == $my_id);
    $cls = $is_me ? 'pm-sent' : 'pm-rec';
    
    // 1. Username FX & Header Setup
    $raw_name = $is_me ? 'You' : $target_name;
    // Check if function exists to avoid crash if bbcode.php isn't updated yet
    $fx_attr = function_exists('get_username_fx') ? get_username_fx($raw_name, ($is_me ? '#fff' : '#6a9c6a')) : "style='color:".($is_me ? '#fff' : '#6a9c6a').";'";
    $header_html = "<span $fx_attr>" . htmlspecialchars($raw_name) . "</span>";
    
    $time = date('H:i', strtotime($row['created_at']));
    $dom_id = "pm_" . $row['id'];
    
    // SPECIAL: BURN REQUEST
    if ($row['message'] === '[SYSTEM::BURN_REQUEST]') {
        echo "<div id='$dom_id' class='burn-request' style='margin-bottom:15px;'>";
        if ($is_me) {
            echo "<div style='background:#220505; color:#e06c75; border:1px solid #e06c75; padding:10px; text-align:center;'>
                    <strong>DESTRUCTION SEQUENCE INITIATED</strong><br>
                    <span style='font-size:0.7rem;'>WAITING FOR PARTNER APPROVAL...</span>
                  </div>";
        } else {
            // Direct Action Link
            echo "<div style='background:#220505; color:#e06c75; border:1px solid #e06c75; padding:15px; text-align:center; margin:10px 0;'>
                    <strong style='font-size:0.9rem; display:block; margin-bottom:5px; animation: pulse-red 1s infinite;'>⚠️ PARTNER REQUESTED WIPE</strong>
                    <div style='font-size:0.7rem; color:#aaa; margin-bottom:10px;'>CLICK TO EXECUTE PROTOCOL</div>
                    <a href='pm_input.php?to={$_GET['to']}&action=confirm_burn&target=top' target='_top' style='background:#e06c75; color:#000; padding:4px 8px; text-decoration:none; font-weight:bold; font-size:0.7rem;'>[ CONFIRM & WIPE NOW ]</a>
                  </div>";
        }
        echo "</div>";
        return;
    }

    $body = parse_bbcode($row['message']);
    
    // Action Buttons
    $actions = "";
    $target_param = $_GET['to'] ?? 0;

    if ($is_me) {
        $del_link = "pm_input.php?action=delete&msg_id={$row['id']}&to={$target_param}";
        $actions = "<a href='$del_link' target='pm_input' style='color:#444; text-decoration:none; margin-left:10px; font-weight:bold;' title='Delete'>[x]</a>";
    } else {
        // [REPLY] - Uses raw_name now
        $clean_text = trim(strip_tags($row['message'])); 
        $reply_qs = http_build_query(['to' => $target_param, 'reply_text' => $clean_text, 'reply_user' => $raw_name]);
        $actions = "<a href='pm_input.php?$reply_qs' target='pm_input' style='color:#555; text-decoration:none; margin-left:10px; font-size:0.65rem;' title='Reply'>[reply]</a>";
    }

    echo "<div id='$dom_id' class='pm-msg $cls'>
            <div class='pm-header'>
                <span>$header_html <span style='color:#444;'>| $time</span></span>
                <span>$actions</span>
            </div>
            <div style='font-family:monospace; white-space:pre-wrap;'>$body</div>
          </div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Force transparent so it blends into the parent container */
        html { height: 100%; }
        body { 
            background: transparent !important; 
            margin: 0; padding: 0; 
            min-height: 100%; 
            /* Force scrollbar to be visible */
            overflow-y: scroll; 
        }
        
        /* NO-JS SCROLL STRATEGY: 
           flex-direction: column-reverse puts the LAST DOM element (newest message) at the TOP.
           This means as new messages stream in, they appear at the top of the viewport
           without needing JS to scrollIntoView().
        */
        .stream-wrapper { 
            min-height: 100%; 
            padding: 50px 20px; 
            display: flex; 
            flex-direction: column-reverse; 
            justify-content: flex-end;      
        }

        /* Message Styling */
        .pm-msg { margin-bottom: 10px; padding: 10px; border: 1px solid #222; max-width: 80%; }
        .pm-sent { background: #111; margin-left: auto; border-color: #333; }
        .pm-rec { background: #0a150a; margin-right: auto; border-color: #1f2f1f; color: #88cc88; }
        .pm-header { 
            font-size: 0.65rem; color: #666; margin-bottom: 5px; 
            border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 3px; 
            display: flex; justify-content: space-between;
        }

        /* WIPE NOTIFICATION */
        .sys-wipe {
            background: #220505; color: #e06c75; border: 1px dashed #e06c75;
            padding: 15px; text-align: center; margin-bottom: 20px; width: 100%;
        }
        .sys-wipe a { color: #fff; font-weight: bold; text-decoration: underline; }

        @keyframes pulse-red { 0% { opacity:1; } 50% { opacity:0.7; } 100% { opacity:1; } }
    </style>
</head>
<body>
    <div id="msg-container" class="stream-wrapper">
    <?php
    // 0. Immediate Wipe Display (Triggered by Redirect)
    if (isset($_GET['wiped'])) {
        echo "<div class='sys-wipe'>
                <strong>[ SYSTEM NOTICE ]</strong><br>
                CONVERSATION HISTORY INCINERATED.<br><br>
                <a href='pm.php?to=$target_id' target='_top'>[ RE-ESTABLISH CONNECTION ]</a>
              </div>";
        exit;
    }

// 1. Initial Load
    // We order by ASC in SQL so Oldest is first, Newest is last.
    $stmt = $pdo->prepare("SELECT * FROM (SELECT * FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
    $stmt->execute([$my_id, $target_id, $target_id, $my_id]);
    $msgs = $stmt->fetchAll();
    
    foreach($msgs as $m) {
        $last_id = $m['id'];
        render_pm($m, $my_id, $t_name);
    }
    
    // Mark Read
    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")->execute([$target_id, $my_id]);
    
    // Initialize Signal Tracker
    $stmt_sig = $pdo->query("SELECT MAX(id) FROM chat_signals");
    $last_sig_id = $stmt_sig->fetchColumn() ?? 0;
    
    flush();

    // 2. Stream Loop
    while(true) {
        
        // --- A. SIGNAL & DELETION HANDLER (Instant CSS Hide) ---
        $s_stmt = $pdo->prepare("SELECT * FROM chat_signals WHERE id > ? AND signal_type = 'DELETE_PM' ORDER BY id ASC");
        $s_stmt->execute([$last_sig_id]);
        $signals = $s_stmt->fetchAll();
        
        foreach($signals as $sig) {
            $last_sig_id = $sig['id'];
            $target_msg = (int)$sig['signal_val'];
            // Instantly hide the message using CSS
            echo "<style>div[id='pm_{$target_msg}'] { display: none !important; }</style>";
        }

        // --- B. CHECK FOR TOTAL WIPEOUT (Robust) ---
        // Only trigger the "Incinerated" screen if the DB is ACTUALLY empty.
        // We perform this check periodically (every ~5 seconds) or if last_id > 0
        if ($last_id > 0) {
             // We don't check if "last_id" exists anymore (that caused the bug).
             // We check if the CONVERSATION count is 0.
             $chk_count = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
             $chk_count->execute([$my_id, $target_id, $target_id, $my_id]);
             if ($chk_count->fetchColumn() == 0) {
                echo "<meta http-equiv='refresh' content='0;url=pm_stream.php?to=$target_id&wiped=1'>";
                exit; 
             }
        }

        // --- C. CHECK NEW MESSAGES ---
       // --- C. CHECK NEW MESSAGES (GUEST-AWARE) ---
$my_type = (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) ? 'guest' : 'user';
$target_type = $_GET['type'] ?? 'user';

$stmt = $pdo->prepare("SELECT * FROM private_messages 
    WHERE (
        (sender_id = ? AND sender_type = ? AND receiver_id = ? AND receiver_type = ?) 
        OR 
        (sender_id = ? AND sender_type = ? AND receiver_id = ? AND receiver_type = ?)
    ) 
    AND id > ? ORDER BY id ASC");

$stmt->execute([
    $my_id, $my_type, $target_id, $target_type, 
    $target_id, $target_type, $my_id, $my_type, 
    $last_id
]);
        $new = $stmt->fetchAll();

        if ($new) {
            foreach($new as $m) {
                $last_id = $m['id'];
                if ($m['receiver_id'] == $my_id) {
                    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE id = ?")->execute([$m['id']]);
                }
                render_pm($m, $my_id, $t_name);
            }
        }

        echo " "; // Filler byte to force flush
        flush();
        
        // SIGNAL BUSY: Touch lock file so chat_stream knows to throttle
        $pm_lock = sys_get_temp_dir() . "/pm_active_" . $my_id . ".lock";
        @touch($pm_lock);

        sleep(1);
        
        if (connection_aborted()) break;
    }
    ?>
    </div>
</body>
</html>