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
    $header_name = $is_me ? 'YOU' : $target_name;
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
            // The button must be in the parent frame, so we just show the status here
            echo "<div style='background:#220505; color:#e06c75; border:1px solid #e06c75; padding:10px; text-align:center; animation: pulse-red 2s infinite;'>
                    <strong>⚠️ PARTNER REQUESTED HISTORY WIPE</strong><br>
                    <span style='font-size:0.7rem;'>CONFIRM DELETION IN TOOLBAR ABOVE</span>
                  </div>";
        }
        echo "</div>";
        return;
    }

    $body = parse_bbcode($row['message']);
    
    // Action Buttons
    $actions = "";
    if ($is_me) {
        $target_param = $_GET['to'] ?? 0;
        $del_link = "pm_input.php?action=delete&msg_id={$row['id']}&to={$target_param}";
        $actions = "<a href='$del_link' target='pm_input' style='color:#444; text-decoration:none; margin-left:10px; font-weight:bold;' title='Delete'>[x]</a>";
    }

    echo "<div id='$dom_id' class='pm-msg $cls'>
            <div class='pm-header'>
                <span>$header_name | $time</span>
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
    // 1. Initial Load
    // We order by ASC in SQL so Oldest is first, Newest is last.
    // CSS column-reverse flips this so Newest (Last) is at the Top.
    $stmt = $pdo->prepare("SELECT * FROM (SELECT * FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
    $stmt->execute([$my_id, $target_id, $target_id, $my_id]);
    $msgs = $stmt->fetchAll();
    
    foreach($msgs as $m) {
        $last_id = $m['id'];
        render_pm($m, $my_id, $t_name);
    }
    
    // Mark Read
    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")->execute([$target_id, $my_id]);
    
    flush();

    // 2. Stream Loop
    while(true) {
        
        // --- CHECK IF WIPED (Burn Logic) ---
        if ($last_id > 0) {
            $chk = $pdo->prepare("SELECT id FROM private_messages WHERE id = ?");
            $chk->execute([$last_id]);
            if (!$chk->fetch()) {
                // DB cleared - Show Manual Reconnect Link (NO JS RELOAD)
                echo "<div class='sys-wipe'>
                        <strong>[ SYSTEM NOTICE ]</strong><br>
                        CONVERSATION HISTORY INCINERATED.<br><br>
                        <a href='pm_stream.php?to=$target_id'>[ RE-ESTABLISH CONNECTION ]</a>
                      </div>";
                exit; // Stop stream
            }
        }

        // --- CHECK NEW MESSAGES ---
        $stmt = $pdo->prepare("SELECT * FROM private_messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND id > ? ORDER BY id ASC");
        $stmt->execute([$my_id, $target_id, $target_id, $my_id, $last_id]);
        $new = $stmt->fetchAll();

        if ($new) {
            foreach($new as $m) {
                $last_id = $m['id'];
                if ($m['receiver_id'] == $my_id) {
                    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE id = ?")->execute([$m['id']]);
                }
                render_pm($m, $my_id, $t_name);
            }
            // NO JS SCROLL NEEDED: CSS column-reverse handles visual order.
        }

        echo " "; // Filler byte to force flush
        flush();
        sleep(1);
        
        // Break loop if client disconnects
        if (connection_aborted()) break;
    }
    ?>
    </div>
</body>
</html>