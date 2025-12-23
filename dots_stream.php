<?php
session_start();
require 'db_config.php';

$gid = (int)($_GET['id'] ?? 0);
$my_id = $_SESSION['user_id'];

// --- COLORS ---
$colors = [0 => '#222', 1 => '#e06c75', 2 => '#6a9c6a']; // 1=Red, 2=Green
$box_colors = [0 => 'transparent', 1 => 'rgba(224, 108, 117, 0.2)', 2 => 'rgba(106, 156, 106, 0.2)'];

// --- FETCH GAME ---
$stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
$stmt->execute([$gid]);
$game = $stmt->fetch();
if (!$game) die("<body style='background:#000;color:#555;'>SIGNAL LOST</body>");

$board = json_decode($game['board_state'], true);
$grid_size = $game['grid_size'];
$my_p_num = ($my_id == $game['p1_id']) ? 1 : (($my_id == $game['p2_id']) ? 2 : 0);
$is_my_turn = ($game['current_turn'] == $my_p_num) && ($game['status'] == 'active');

// --- MOVE LOGIC (Input Processing) ---
if ($is_my_turn && isset($_GET['move'])) {
    list($dir, $r, $c) = explode('_', $_GET['move']);
    $r = (int)$r; $c = (int)$c;
    
    $valid = false;
    if ($dir === 'h' && isset($board['h'][$r][$c]) && $board['h'][$r][$c] == 0) $valid = true;
    if ($dir === 'v' && isset($board['v'][$r][$c]) && $board['v'][$r][$c] == 0) $valid = true;

    if ($valid) {
        $board[$dir][$r][$c] = $my_p_num;
        $made_box = false;
        
        // Helper to check closure
        $check = function($br, $bc) use (&$board, $my_p_num) {
            if ($board['h'][$br][$bc] > 0 && $board['h'][$br+1][$bc] > 0 && 
                $board['v'][$br][$bc] > 0 && $board['v'][$br][$bc+1] > 0 && 
                $board['b'][$br][$bc] == 0) {
                $board['b'][$br][$bc] = $my_p_num;
                $board['s']['p'.$my_p_num]++;
                return true;
            }
            return false;
        };

        if ($dir === 'h') {
            if ($r > 0 && $check($r-1, $c)) $made_box = true;
            if ($r < $grid_size && $check($r, $c)) $made_box = true;
        } else {
            if ($c > 0 && $check($r, $c-1)) $made_box = true;
            if ($c < $grid_size && $check($r, $c)) $made_box = true;
        }

        $next_turn = $made_box ? $my_p_num : (($my_p_num == 1) ? 2 : 1);
        
        // Win Check
        $total = $board['s']['p1'] + $board['s']['p2'];
        $status = 'active'; $winner = null;
        if ($total >= ($grid_size * $grid_size)) {
            $status = 'finished';
            $winner = ($board['s']['p1'] > $board['s']['p2']) ? $game['p1_id'] : $game['p2_id'];
            if ($board['s']['p1'] == $board['s']['p2']) $winner = 0;
        }

        $up = $pdo->prepare("UPDATE games SET board_state = ?, current_turn = ?, status = ?, winner = ?, last_move = NOW() WHERE id = ?");
        $up->execute([json_encode($board), $next_turn, $status, $winner, $gid]);
        
        header("Location: dots_stream.php?id=$gid"); exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php if(!$is_my_turn && $game['status'] == 'active'): ?>
        <meta http-equiv="refresh" content="1">
    <?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <style>
        body { text-align: center; user-select: none; padding-top: 20px; background: #0d0d0d; }
        .game-board { display: inline-block; background: #080808; padding: 30px; border: 2px solid #333; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
        .row { display: flex; }
        
        .dot { width: 14px; height: 14px; background: #ccc; border-radius: 50%; z-index: 2; position: relative; }
        .h-line { width: 60px; height: 14px; background: #1a1a1a; position: relative; }
        .v-line { width: 14px; height: 60px; background: #1a1a1a; position: relative; }
        
        .line-link { width: 100%; height: 100%; display: block; opacity: 0; position: absolute; top:0; left:0; z-index: 10; }
        .line-link:hover { opacity: 0.4; background: #fff; cursor: pointer; }
        
        .box { width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-family: monospace; font-size: 1.2rem; }
        .p1-txt { color: #e06c75; } .p2-txt { color: #6a9c6a; }
        
        .turn-ind { padding: 10px 20px; border: 1px solid #333; display: inline-block; margin: 10px; width: 180px; background: #111; }
        .active-turn { border-color: #fff; transform: scale(1.05); }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>">
    <div style="margin-bottom: 30px;">
        <div class="turn-ind <?= ($game['current_turn']==1)?'active-turn':'' ?>" style="border-top: 3px solid <?= $colors[1] ?>;">
            <div class="p1-txt"><?= htmlspecialchars($game['p1_name']) ?></div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?= $board['s']['p1'] ?></div>
        </div>
        <div class="turn-ind <?= ($game['current_turn']==2)?'active-turn':'' ?>" style="border-top: 3px solid <?= $colors[2] ?>;">
            <div class="p2-txt"><?= htmlspecialchars($game['p2_name']) ?></div>
            <div style="font-size: 1.5rem; font-weight: bold;"><?= $board['s']['p2'] ?></div>
        </div>
    </div>

    <?php if($game['status'] == 'finished'): ?>
        <h1 style="color: #e5c07b;">GAME OVER</h1>
        <div style="margin-bottom: 30px; font-size: 1.2rem;">
            <?= ($game['winner'] == 0) ? "DRAW" : "VICTOR: " . (($game['winner'] == $game['p1_id']) ? $game['p1_name'] : $game['p2_name']) ?>
        </div>
        <a href="games.php" target="_top" class="btn-primary">RETURN TO LOBBY</a>
    <?php else: ?>
        <div style="color: #666; font-size: 0.9rem; margin-bottom: 10px; font-family: monospace;">
            <?= ($is_my_turn) ? ">> AWAITING YOUR INPUT <<" : ">> RECEIVING OPPONENT DATA... <<" ?>
        </div>
    <?php endif; ?>

    <div class="game-board">
        <?php for($r=0; $r <= $grid_size; $r++): ?>
            <div class="row">
                <?php for($c=0; $c < $grid_size; $c++): ?>
                    <div class="dot"></div>
                    <?php $owner = $board['h'][$r][$c]; ?>
                    <div class="h-line" style="background: <?= ($owner>0)?$colors[$owner]:'#1a1a1a' ?>;">
                        <?php if($owner == 0 && $is_my_turn): ?>
                            <a href="?id=<?= $gid ?>&move=h_<?= $r ?>_<?= $c ?>" class="line-link"></a>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
                <div class="dot"></div>
            </div>
            <?php if($r < $grid_size): ?>
            <div class="row">
                <?php for($c=0; $c <= $grid_size; $c++): ?>
                    <?php $owner = $board['v'][$r][$c]; ?>
                    <div class="v-line" style="background: <?= ($owner>0)?$colors[$owner]:'#1a1a1a' ?>;">
                        <?php if($owner == 0 && $is_my_turn): ?>
                            <a href="?id=<?= $gid ?>&move=v_<?= $r ?>_<?= $c ?>" class="line-link"></a>
                        <?php endif; ?>
                    </div>
                    <?php if($c < $grid_size): 
                        $b_own = $board['b'][$r][$c];
                        $bg = $box_colors[$b_own];
                        $txt = ($b_own > 0) ? "P$b_own" : "";
                    ?>
                    <div class="box <?= ($b_own==1)?'p1-txt':'p2-txt' ?>" style="background: <?= $bg ?>;"><?= $txt ?></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
</body>
</html>