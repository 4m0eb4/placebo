<?php
session_start();
require 'db_config.php';

// 1. SECURITY & VALIDATION
if (!isset($_SESSION['fully_authenticated'])) exit; // Silent exit for security

$user_id = $_SESSION['user_id'];
$gid = (int)($_GET['id'] ?? 0);
$move_str = $_GET['move'] ?? '';

if (!$gid || !$move_str) exit;

// 2. DATABASE TRANSACTION (Prevents Race Conditions)
$pdo->beginTransaction();

try {
    // Fetch Game with Lock
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? FOR UPDATE");
    $stmt->execute([$gid]);
    $game = $stmt->fetch();

    // Basic Integrity Checks
    if (!$game || $game['status'] !== 'active') {
        $pdo->rollBack(); exit;
    }

    // Identity Check
    $p_num = 0;
    if ($game['p1_id'] == $user_id) $p_num = 1;
    elseif ($game['p2_id'] == $user_id) $p_num = 2;

    // Not a player OR not their turn? Exit.
    if ($p_num === 0 || $game['current_turn'] != $p_num) {
        $pdo->rollBack(); exit;
    }

    $board = json_decode($game['board_state'], true);
    $size = $game['grid_size'];
    
    // 3. PARSE MOVE (Format: "h_row_col" or "v_row_col")
    $parts = explode('_', $move_str);
    if (count($parts) !== 3) { $pdo->rollBack(); exit; }
    
    $type = $parts[0]; // 'h' or 'v'
    $r = (int)$parts[1];
    $c = (int)$parts[2];

    // Validate Coordinates & Availability
    $valid = false;
    if ($type === 'h') {
        // Horizontal Limits: Rows 0 to Size, Cols 0 to Size-1
        if ($r >= 0 && $r <= $size && $c >= 0 && $c < $size) {
            if ($board['h'][$r][$c] == 0) $valid = true;
        }
    } elseif ($type === 'v') {
        // Vertical Limits: Rows 0 to Size-1, Cols 0 to Size
        if ($r >= 0 && $r < $size && $c >= 0 && $c <= $size) {
            if ($board['v'][$r][$c] == 0) $valid = true;
        }
    }

    if (!$valid) { $pdo->rollBack(); exit; }

    // 4. APPLY MOVE
    $board[$type][$r][$c] = $p_num;
    
    // 5. CHECK FOR COMPLETED BOXES
    $boxes_made = 0;
    
    // Improved Closure: Explicitly validates boundaries and side ownership
    $check_box = function($br, $bc) use (&$board, $p_num, &$boxes_made, $size) {
        // Ensure coordinates are within grid limits
        if ($br < 0 || $br >= $size || $bc < 0 || $bc >= $size) return;

        // Verify all 4 sides of this specific box index
        $top    = $board['h'][$br][$bc];
        $bottom = $board['h'][$br+1][$bc];
        $left   = $board['v'][$br][$bc];
        $right  = $board['v'][$br][$bc+1];

        if ($top > 0 && $bottom > 0 && $left > 0 && $right > 0 && $board['b'][$br][$bc] == 0) {
            $board['b'][$br][$bc] = $p_num; 
            $boxes_made++;
        }
    };

    // Trigger checks based on the line type placed
    if ($type === 'h') {
        $check_box($r - 1, $c); // Check above
        $check_box($r, $c);     // Check below
    } else {
        $check_box($r, $c - 1); // Check left
        $check_box($r, $c);     // Check right
    }

    // 6. UPDATE GAME STATE
    if ($boxes_made > 0) {
        $board['s']['p'.$p_num] += $boxes_made;
        $next_turn = $p_num; // Player keeps turn for scoring!
    } else {
        $next_turn = ($p_num == 1) ? 2 : 1; // Swap turn
    }

    // Check for Game Over
    $total_boxes = $size * $size;
    $taken_boxes = $board['s']['p1'] + $board['s']['p2'];
    $new_status = 'active';
    $winner = 0;

    if ($taken_boxes >= $total_boxes) {
        $new_status = 'finished';
        if ($board['s']['p1'] > $board['s']['p2']) $winner = $game['p1_id'];
        elseif ($board['s']['p2'] > $board['s']['p1']) $winner = $game['p2_id'];
        else $winner = 0; // Draw
    }

    // 7. SAVE TO DB
    // Updating 'last_move' triggers the stream to refresh
    $stmt = $pdo->prepare("UPDATE games SET board_state = ?, current_turn = ?, status = ?, winner = ?, last_move = NOW() WHERE id = ?");
    $stmt->execute([json_encode($board), $next_turn, $new_status, $winner, $gid]);

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
}
// No output required; hidden iframe just loads this and stops.
?>