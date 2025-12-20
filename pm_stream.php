<?php
// pm_stream.php - Streaming Engine for PMs
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
session_start();
require 'db_config.php';
require 'bbcode.php';
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
    
    // MATCHING YOUR ORIGINAL STYLE STRUCTURE
    echo "<div id='$dom_id' class='pm-msg $cls'>
            <div class='pm-header'>$header_name | $time</div>
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
            overflow-y: scroll; /* Force scrollbar */
        }
        
        /* Scroll container - REVERSED for Newest at Top */
        .stream-wrapper { 
            min-height: 100%; 
            /* FIXED: Extra padding to ensure top/bottom messages aren't cut off */
            padding: 50px 20px; 
            display: flex; 
            flex-direction: column-reverse; 
            justify-content: flex-end;      
        }

        /* Message Styling */
        .pm-msg { margin-bottom: 10px; padding: 10px; border: 1px solid #222; max-width: 80%; }
        .pm-sent { background: #111; margin-left: auto; border-color: #333; }
        .pm-rec { background: #0a150a; margin-right: auto; border-color: #1f2f1f; color: #88cc88; }
        .pm-header { font-size: 0.65rem; color: #666; margin-bottom: 5px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 3px; }
        
        @keyframes pulse-red { 0% { opacity:1; } 50% { opacity:0.7; } 100% { opacity:1; } }
    </style>
</head>
<body>
    <div id="msg-container" class="stream-wrapper">
    <?php
    // 1. Initial Load
    $stmt = $pdo->prepare("SELECT * FROM (SELECT * FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
    $stmt->execute([$my_id, $target_id, $target_id, $my_id]);
    $msgs = $stmt->fetchAll();
    
    foreach($msgs as $m) {
        $last_id = $m['id'];
        render_pm($m, $my_id, $t_name);
    }
    
    // Mark Read
    $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")->execute([$target_id, $my_id]);
    
    // No JS Scroll needed. Column-reverse puts the focus at the top naturally.
    flush();

    // 2. Stream Loop
    while(true) {
        // Check if conversation deleted (Burn detection)
        // If we have a last_id but the message no longer exists, wipe logic
        if ($last_id > 0) {
            $chk = $pdo->prepare("SELECT id FROM private_messages WHERE id = ?");
            $chk->execute([$last_id]);
            if (!$chk->fetch()) {
                // DB cleared
                echo "<script>location.reload();</script>";
                exit;
            }
        }

        // Check for new messages
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
            echo '<script>window.scrollTo(0, document.body.scrollHeight);</script>';
        }

        echo " "; flush();
        sleep(1);
        if (connection_aborted()) break;
    }
    ?>
    </div>
</body>
</html>