<?php
// dots_stream.php - STREAMING VERSION (No-JS)
// Prevents flickering and server spam by holding connection open
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();
set_time_limit(0);

header('X-Accel-Buffering: no');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();
require 'db_config.php';
session_write_close(); // Release lock

$gid = (int)($_GET['id'] ?? 0);
if (!$gid) die("INVALID GAME ID");

// --- COLORS ---
$colors = [0 => '#222', 1 => '#e06c75', 2 => '#6a9c6a']; 
$box_colors = [0 => 'transparent', 1 => 'rgba(224, 108, 117, 0.2)', 2 => 'rgba(106, 156, 106, 0.2)'];

// State Tracking
$last_move_time = null;
$heartbeat = 0;

// Function to render a single frame
function render_game_frame($gid, $colors, $box_colors, $dom_id) {
    global $pdo;
    
    // Re-fetch Game Data
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$gid]);
    $game = $stmt->fetch();
    
    if (!$game) return false;

    $board = json_decode($game['board_state'], true);
    $grid_size = $game['grid_size'];
    $my_id = $_SESSION['user_id'] ?? 0;
    
    $my_p_num = ($my_id == $game['p1_id']) ? 1 : (($my_id == $game['p2_id']) ? 2 : 0);
    $is_my_turn = ($game['current_turn'] == $my_p_num) && ($game['status'] == 'active');
    // Aggressive CSS to ensure only current frame is visible and previous ones are removed from flow
    echo "<style>
        .game-frame { display: none !important; height: 0; overflow: hidden; } 
        #$dom_id { display: block !important; height: auto; overflow: visible; }
    </style>";
    
    // Output HTML
    echo "<div id='$dom_id' class='game-frame'>";
    // Output CSS to show THIS frame and hide previous
    echo "<style>.game-frame { display: none; } #$dom_id { display: block; }</style>";
    
    // Output HTML
    echo "<div id='$dom_id' class='game-frame'>";
    
    // Header Info
    echo "<div style='margin-bottom: 20px;'>
            <div class='turn-ind " . ($game['current_turn']==1?'active-turn':'') . "' style='border-top: 3px solid {$colors[1]};'>
                <div class='p1-txt'>" . htmlspecialchars($game['p1_name']) . "</div>
                <div style='font-size: 1.5rem; font-weight: bold;'>{$board['s']['p1']}</div>
            </div>
            <div class='turn-ind " . ($game['current_turn']==2?'active-turn':'') . "' style='border-top: 3px solid {$colors[2]};'>
                <div class='p2-txt'>" . htmlspecialchars($game['p2_name']) . "</div>
                <div style='font-size: 1.5rem; font-weight: bold;'>{$board['s']['p2']}</div>
            </div>
          </div>";

    if ($game['status'] == 'finished') {
        $w_txt = ($game['winner'] == 0) ? "DRAW" : "VICTOR: " . (($game['winner'] == $game['p1_id']) ? $game['p1_name'] : $game['p2_name']);
        echo "<h1 style='color: #e5c07b;'>GAME OVER</h1>
              <div style='margin-bottom: 30px; font-size: 1.2rem;'>$w_txt</div>
              <a href='games.php' target='_top' class='btn-primary'>RETURN TO LOBBY</a>";
    } else {
        $status_txt = ($is_my_turn) ? ">> AWAITING YOUR INPUT <<" : ">> RECEIVING OPPONENT DATA... <<";
        echo "<div style='color: #666; font-size: 0.9rem; margin-bottom: 10px; font-family: monospace;'>$status_txt</div>";
    }

    // Board
    echo "<div class='game-board'>";
    for($r=0; $r <= $grid_size; $r++) {
        echo "<div class='row'>";
        for($c=0; $c < $grid_size; $c++) {
            echo "<div class='dot'></div>";
            $owner = $board['h'][$r][$c];
            $bg = ($owner>0) ? $colors[$owner] : '#1a1a1a';
            echo "<div class='h-line' style='background: $bg;'>";
            if($owner == 0 && $is_my_turn) {
                echo "<a href='dots_action.php?id=$gid&move=h_{$r}_{$c}' target='game_hidden_frame' class='line-link'></a>";
            }
            echo "</div>";
        }
        echo "<div class='dot'></div></div>";
        
        if($r < $grid_size) {
            echo "<div class='row'>";
            for($c=0; $c <= $grid_size; $c++) {
                $owner = $board['v'][$r][$c];
                $bg = ($owner>0) ? $colors[$owner] : '#1a1a1a';
                echo "<div class='v-line' style='background: $bg;'>";
                if($owner == 0 && $is_my_turn) {
                    echo "<a href='dots_action.php?id=$gid&move=v_{$r}_{$c}' target='game_hidden_frame' class='line-link'></a>";
                }
                echo "</div>";
                
                if($c < $grid_size) {
                    $b_own = $board['b'][$r][$c];
                    $bg_box = $box_colors[$b_own];
                    $txt = ($b_own > 0) ? "P$b_own" : "";
                    $cls = ($b_own==1) ? 'p1-txt' : 'p2-txt';
                    echo "<div class='box $cls' style='background: $bg_box;'>$txt</div>";
                }
            }
            echo "</div>";
        }
    }
    echo "</div></div>"; // Close board/frame
    
    return $game['last_move'];
}

// --- HTML SHELL ---
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
<style>
        body { text-align: center; user-select: none; padding-top: 20px; background: #0d0d0d; color: #ccc; }
        /* Hidden by default to prevent stacking */
        .game-frame { display: none; } 
        .game-board { display: inline-block; background: #080808; padding: 30px; border: 1px solid #333; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
        .row { display: flex; align-items: center; justify-content: center; }
        .dot { width: 10px; height: 10px; background: #444; border-radius: 50%; z-index: 5; position: relative; flex-shrink: 0; }
        
        /* Thinner lines with slightly negative margins to connect to dots perfectly */
        .h-line { width: 80px; height: 4px; background: #1a1a1a; position: relative; flex-shrink: 0; margin: 0 -2px; }
        .v-line { width: 4px; height: 80px; background: #1a1a1a; position: relative; flex-shrink: 0; margin: -2px 0; }
        
        /* HITBOXES: Invisible but much larger than the line (30px) for Tor Browser usability */
        /* Streamlined Selection: Transparent hitboxes with a subtle white indicator on hover */
        .line-link { 
            width: 100%; height: 26px; 
            display: block; opacity: 0; position: absolute; 
            top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 50; 
            transition: opacity 0.1s;
        }
        .v-line .line-link { width: 26px; height: 100%; }
        /* Removed bulky grey background, using a soft white line hint instead */
        .line-link:hover { opacity: 0.2; background: #fff; border: 1px solid #fff; cursor: pointer; box-shadow: 0 0 5px #fff; }
        
        .box { width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-family: monospace; font-size: 1.5rem; flex-shrink: 0; }
        .p1-txt { color: #e06c75; } .p2-txt { color: #6a9c6a; }
        .turn-ind { padding: 10px 20px; border: 1px solid #333; display: inline-block; margin: 10px; width: 180px; background: #111; opacity: 0.5; transition: 0.3s; }
        .active-turn { border-color: #fff; transform: scale(1.05); opacity: 1; }
    </style>
</head>
<body>
    <iframe name="game_hidden_frame" style="display:none;"></iframe>

    <?php
    while (true) {
        $heartbeat++;
        
        // Check for updates
        $chk = $pdo->prepare("SELECT last_move FROM games WHERE id = ?");
        $chk->execute([$gid]);
        $current_last_move = $chk->fetchColumn();

        // Render if new move OR first run
        if ($current_last_move !== $last_move_time || $last_move_time === null) {
            $dom_id = "frame_" . time() . "_" . rand(1000,9999);
            $last_move_time = render_game_frame($gid, $colors, $box_colors, $dom_id);
            echo " "; flush();
        }

        // Keep connection alive
        if ($heartbeat % 15 === 0) { echo ""; flush(); }
        
        // Break if connection lost
        if (connection_aborted()) break;
        
        usleep(500000); // 0.5s check
    }
    ?>
</body>
</html>