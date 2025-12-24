<?php
session_start();
require 'db_config.php';

// DEBUG LOGGING FUNCTION
function log_action($msg) {
    file_put_contents('debug_dots.txt', date('[H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

log_action("Action script triggered.");

// 1. SECURITY & VALIDATION
if (!isset($_SESSION['fully_authenticated'])) {
    log_action("FAIL: Not authenticated.");
    exit;
}

// Identity Check (User vs Guest)
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
    $user_id = -1 * abs($_SESSION['guest_token_id'] ?? 0);
} else {
    $user_id = $_SESSION['user_id'];
}

$gid = $_GET['id'] ?? '';
$move_str = $_GET['move'] ?? '';

log_action("User: $user_id | Game: $gid | Move: $move_str");

if (!$gid || !$move_str) {
    log_action("FAIL: Missing params.");
    exit;
}

// 2. DATABASE TRANSACTION
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("SELECT * FROM games WHERE public_id = ? FOR UPDATE");
    $stmt->execute([$gid]);
    $game = $stmt->fetch();

    if (!$game || $game['status'] !== 'active') {
        log_action("FAIL: Game not active or not found.");
        $pdo->rollBack(); exit;
    }

    // Identity Check
    $p_num = 0;
    if ($game['p1_id'] == $user_id) $p_num = 1;
    elseif ($game['p2_id'] == $user_id) $p_num = 2;

    log_action("Player Num: $p_num | Current Turn: " . $game['current_turn']);

    if ($p_num === 0 || $game['current_turn'] != $p_num) {
        log_action("FAIL: Not player's turn.");
        $pdo->rollBack(); exit;
    }

    $board = json_decode($game['board_state'], true);
    $size = $game['grid_size'];
    
    // 3. PARSE MOVE
    $parts = explode('_', $move_str);
    if (count($parts) !== 3) { $pdo->rollBack(); exit; }
    
    $type = $parts[0];
    $r = (int)$parts[1];
    $c = (int)$parts[2];

    // Validate
    $valid = false;
    if ($type === 'h') {
        if ($r >= 0 && $r <= $size && $c >= 0 && $c < $size) {
            if ($board['h'][$r][$c] == 0) $valid = true;
        }
    } elseif ($type === 'v') {
        if ($r >= 0 && $r < $size && $c >= 0 && $c <= $size) {
            if ($board['v'][$r][$c] == 0) $valid = true;
        }
    }

    if (!$valid) {
        log_action("FAIL: Invalid move or line taken.");
        $pdo->rollBack(); exit;
    }

    // 4. APPLY MOVE
    $board[$type][$r][$c] = $p_num;
    $boxes_made = 0;
    
    $check_box = function($br, $bc) use (&$board, $p_num, &$boxes_made, $size) {
        if ($br < 0 || $br >= $size || $bc < 0 || $bc >= $size) return;
        $top    = $board['h'][$br][$bc];
        $bottom = $board['h'][$br+1][$bc];
        $left   = $board['v'][$br][$bc];
        $right  = $board['v'][$br][$bc+1];
        if ($top > 0 && $bottom > 0 && $left > 0 && $right > 0 && $board['b'][$br][$bc] == 0) {
            $board['b'][$br][$bc] = $p_num; 
            $boxes_made++;
        }
    };

    if ($type === 'h') {
        $check_box($r - 1, $c);
        $check_box($r, $c);
    } else {
        $check_box($r, $c - 1);
        $check_box($r, $c);
    }

    // 6. UPDATE STATE
    if ($boxes_made > 0) {
        $board['s']['p'.$p_num] += $boxes_made;
        $next_turn = $p_num;
    } else {
        $next_turn = ($p_num == 1) ? 2 : 1;
    }

    $taken_boxes = $board['s']['p1'] + $board['s']['p2'];
    $total_boxes = $size * $size;
    $new_status = 'active';
    $winner = 0;

    if ($taken_boxes >= $total_boxes) {
        $new_status = 'finished';
        if ($board['s']['p1'] > $board['s']['p2']) $winner = $game['p1_id'];
        elseif ($board['s']['p2'] > $board['s']['p1']) $winner = $game['p2_id'];
    }

    $stmt = $pdo->prepare("UPDATE games SET board_state = ?, current_turn = ?, status = ?, winner = ?, last_move = NOW() WHERE id = ?");
    $stmt->execute([json_encode($board), $next_turn, $new_status, $winner, $game['id']]);

    $pdo->commit();
    log_action("SUCCESS: Move applied.");

} catch (Exception $e) {
    log_action("CRITICAL ERROR: " . $e->getMessage());
    $pdo->rollBack();
}
?>