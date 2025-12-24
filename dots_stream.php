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

$gid = $_GET['id'] ?? '';
if (!$gid) die("INVALID GAME ID");

// --- COLORS ---
$colors = [0 => '#222', 1 => '#e06c75', 2 => '#6a9c6a']; 
$box_colors = [0 => 'transparent', 1 => 'rgba(224, 108, 117, 0.2)', 2 => 'rgba(106, 156, 106, 0.2)'];

// State Tracking
$last_move_time = null;
$heartbeat = 0;

// Function to render a single frame
function render_game_frame($gid, $colors, $box_colors, $dom_id, $prev_dom_id = null) {
    global $pdo;
    
    // Re-fetch Game Data
    $stmt = $pdo->prepare("SELECT * FROM games WHERE public_id = ?");
    $stmt->execute([$gid]);
    $game = $stmt->fetch();
    
    if (!$game) return false;

    $board = json_decode($game['board_state'], true);
    $grid_size = $game['grid_size'];
    $my_id = $_SESSION['user_id'] ?? 0;
    
    $my_p_num = ($my_id == $game['p1_id']) ? 1 : (($my_id == $game['p2_id']) ? 2 : 0);
    $is_my_turn = ($game['current_turn'] == $my_p_num) && ($game['status'] == 'active');
    
    // 1. Output NEW Frame (Force visible with ID specific style)
    echo "<style>#$dom_id { display: flex !important; }</style>";
    echo "<div id='$dom_id' class='game-frame'>";
    
    // Header Info
    echo "<div class='hud-panel'>
            <div class='turn-ind " . ($game['current_turn']==1?'active-turn':'') . "' style='border-top: 3px solid {$colors[1]};'>
                <div class='p1-txt'>" . htmlspecialchars($game['p1_name']) . "</div>
                <div style='font-size: 1.2rem; font-weight: bold;'>{$board['s']['p1']}</div>
            </div>
            <div class='turn-ind " . ($game['current_turn']==2?'active-turn':'') . "' style='border-top: 3px solid {$colors[2]};'>
                <div class='p2-txt'>" . htmlspecialchars($game['p2_name']) . "</div>
                <div style='font-size: 1.2rem; font-weight: bold;'>{$board['s']['p2']}</div>
            </div>
          </div>";

    if ($game['status'] == 'finished') {
        $w_txt = ($game['winner'] == 0) ? "DRAW" : "VICTOR: " . (($game['winner'] == $game['p1_id']) ? $game['p1_name'] : $game['p2_name']);
        echo "<div style='text-align:center; z-index:100; background:rgba(0,0,0,0.9); padding:20px; border:1px solid #e5c07b; position:absolute;'>
                <h1 style='color: #e5c07b; margin:0 0 10px 0;'>GAME OVER</h1>
                <div style='margin-bottom: 20px; font-size: 1rem;'>$w_txt</div>
                <a href='games.php' target='_top' class='btn-primary' style='padding:10px 20px; text-decoration:none; background:#222; border:1px solid #666; color:#fff;'>RETURN TO LOBBY</a>
              </div>";
    } else {
        $status_txt = ($is_my_turn) ? ">> AWAITING YOUR INPUT <<" : ">> RECEIVING OPPONENT DATA... <<";
        echo "<div style='color: #666; font-size: 0.8rem; margin-bottom: 5px; font-family: monospace;'>$status_txt</div>";
    }

    // Board
    echo "<div class='game-board'>";
    for($r=0; $r <= $grid_size; $r++) {
        // Horizontal Row (Dots & H-Lines)
        echo "<div class='row-h'>";
        for($c=0; $c < $grid_size; $c++) {
            echo "<div class='dot'></div>";
            $owner = $board['h'][$r][$c];
            $bg = ($owner>0) ? $colors[$owner] : '#1a1a1a';
            echo "<div class='h-line' style='background: $bg;'>";
            if($owner == 0 && $is_my_turn) {
                echo "<a href='dots_action.php?id=$gid&move=h_{$r}_{$c}' target='game_hidden_frame' class='line-link'>&nbsp;</a>";
            }
            echo "</div>";
        }
        echo "<div class='dot'></div></div>"; // End Horz Row
        
        // Vertical Row (V-Lines & Boxes)
        if($r < $grid_size) {
            echo "<div class='row-v'>";
            for($c=0; $c <= $grid_size; $c++) {
                $owner = $board['v'][$r][$c];
                $bg = ($owner>0) ? $colors[$owner] : '#1a1a1a';
                echo "<div class='v-line' style='background: $bg;'>";
                if($owner == 0 && $is_my_turn) {
                    echo "<a href='dots_action.php?id=$gid&move=v_{$r}_{$c}' target='game_hidden_frame' class='line-link'>&nbsp;</a>";
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
            echo "</div>"; // End Vert Row
        }
    }
    echo "</div></div>"; // Close board/frame
    
    // 2. Hide PREVIOUS Frame (Clean up stack)
    if ($prev_dom_id) {
        echo "<style>#$prev_dom_id { display: none !important; }</style>";
    }
    
    return $game['last_move'];
}

// --- HTML SHELL ---
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        html, body { 
            margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden;
            background: #0d0d0d; color: #ccc; 
            display: flex; align-items: center; justify-content: center; 
            user-select: none;
        }

        /* FRAME LOGIC */
        .game-frame { 
            display: none; 
            width: 100%; height: 100%; 
            position: absolute; top: 0; left: 0;
            flex-direction: column; align-items: center; justify-content: center;
        } 

        /* BOARD: Fixed Square relative to viewport (vmin) */
        .game-board { 
            width: 65vmin; height: 65vmin; 
            background: #080808; 
            padding: 1vmin; 
            border: 1px solid #333; 
            box-shadow: 0 0 30px rgba(0,0,0,0.7); 
            display: flex; flex-direction: column;
        }

        /* ROW TYPES */
        /* Horz: Items centered (Dots) */
        .row-h { flex: 0 0 auto; display: flex; align-items: center; } 
        /* Vert: Items stretched to fill height (Lines/Boxes) */
        .row-v { flex: 1; display: flex; align-items: stretch; }

        /* DOTS & LINES */
        .dot { width: 1.5vmin; height: 1.5vmin; background: #444; border-radius: 50%; z-index: 5; position: relative; }
        
        .h-line { flex: 1; height: 0.5vmin; background: #1a1a1a; position: relative; margin: 0 -0.2vmin; }
        .v-line { width: 0.5vmin; flex: 0 0 auto; background: #1a1a1a; position: relative; margin: -0.2vmin 0; }
        
/* HITBOX CONTAINER (Invisible Large Click Area) */
        .line-link { 
            width: 100%; height: 3.5vmin; /* Wide area for easy clicking */
            display: block; 
            background-color: rgba(255, 255, 255, 0.01); /* Solid but invisible */
            position: absolute; 
            top: 50%; left: 50%; transform: translate(-50%, -50%); 
            z-index: 9999;
            cursor: crosshair;
            border: none; outline: none; text-decoration: none;
        }
        /* Vertical Hitbox Dimensions */
        .v-line .line-link { width: 3.5vmin; height: 100%; }

        /* GHOST LINE (The Visual Highlight) */
        /* This creates a fake line inside the link that matches the board line size */
        .line-link::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: transparent;
            transition: background 0.1s, box-shadow 0.1s;
        }

        /* Match Horizontal Visual Line Size (0.5vmin) */
        .h-line .line-link::after { width: 100%; height: 0.5vmin; }

        /* Match Vertical Visual Line Size (0.5vmin) */
        .v-line .line-link::after { width: 0.5vmin; height: 100%; }

        /* ON HOVER: Light up ONLY the ghost line, not the whole box */
        .line-link:hover::after {
            background: #ffffff;
            box-shadow: 0 0 8px #ffffff; /* Neon Glow */
        }
        
        /* Disable background change on the main box */
        .line-link:hover { background-color: rgba(255,255,255,0.01); }
        /* BOXES & TEXT */
        .box { flex: 1; display: flex; align-items: center; justify-content: center; font-weight: bold; font-family: monospace; font-size: 2vmin; }
        .p1-txt { color: #e06c75; } .p2-txt { color: #6a9c6a; }
        
        /* HUD */
        .hud-panel { margin-bottom: 2vmin; display: flex; gap: 20px; }
        .turn-ind { padding: 10px 20px; border: 1px solid #333; display: inline-block; width: 150px; text-align: center; background: #111; opacity: 0.5; transition: 0.3s; }
        .active-turn { border-color: #fff; transform: scale(1.05); opacity: 1; box-shadow: 0 0 10px rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <iframe name="game_hidden_frame" style="display:none;"></iframe>

    <?php
    $last_dom_id = null;
    $last_chk_status = '';

    while (true) {
        $heartbeat++;
        
        $chk = $pdo->prepare("SELECT last_move, status FROM games WHERE public_id = ?");
        $chk->execute([$gid]);
        $state = $chk->fetch();
        $current_last_move = $state['last_move'] ?? '';
        $current_status = $state['status'] ?? '';

        if ($current_last_move !== $last_move_time || $current_status !== $last_chk_status || $last_move_time === null) {
            $dom_id = "frame_" . time() . "_" . rand(1000,9999);
            
            $last_move_time = render_game_frame($gid, $colors, $box_colors, $dom_id, $last_dom_id);
            $last_chk_status = $current_status;
            $last_dom_id = $dom_id;
            
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