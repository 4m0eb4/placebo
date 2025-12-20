<?php
// chat_action.php
session_start();
require 'db_config.php';

// Safe Redirect Helper
function go_back() {
    header("Location: chat_input.php");
    exit;
}

if (!isset($_SESSION['fully_authenticated'])) go_back();

try {
    // 1. DELETE ACTION
    if (isset($_GET['del'])) {
        $id = (int)$_GET['del'];
        $my_rank = $_SESSION['rank'] ?? 1;
        $my_user = $_SESSION['username'] ?? '';

        // Fetch message to verify ownership
        $stmt_check = $pdo->prepare("SELECT username FROM chat_messages WHERE id = ?");
        $stmt_check->execute([$id]);
        $msg_row = $stmt_check->fetch();

        if ($msg_row) {
            // Allow if Admin (Rank >= 5) OR if Username matches (Owner)
            if ($my_rank >= 5 || $msg_row['username'] === $my_user) {
                $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE id = ?");
                $stmt->execute([$id]);
                
                // SIGNAL: Instant Delete for all users
                $pdo->prepare("INSERT INTO chat_signals (signal_type, signal_val) VALUES ('DELETE', ?)")->execute([$id]);
            }
        }
    }

    // 2. REACTION ACTION
    if (isset($_GET['react']) && isset($_GET['id']) && isset($_GET['emoji'])) {
        $msg_id = (int)$_GET['id'];
        $emoji = trim($_GET['emoji']);
        
        // Basic validation - Switched to strlen (bytes) for safety & increased limit
        if (strlen($emoji) <= 32 && $msg_id > 0) {
            
            // A. Check if already reacted (TOGGLE LOGIC)
            $check = $pdo->prepare("SELECT id FROM chat_reactions WHERE message_id=? AND user_id=? AND emoji=?");
            $check->execute([$msg_id, $_SESSION['user_id'], $emoji]);
            $already_reacted = $check->fetch();
            
            if ($already_reacted) {
                // TOGGLE OFF: Remove existing reaction
                $del = $pdo->prepare("DELETE FROM chat_reactions WHERE id=?");
                $del->execute([$already_reacted['id']]);
                
                // SIGNAL: Update immediately
                $pdo->prepare("INSERT INTO chat_signals (signal_type, signal_val) VALUES ('REACT', ?)")->execute([$msg_id]);
            } else {
                // ADD NEW: Check limit first
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_reactions WHERE message_id=? AND user_id=?");
                $count_stmt->execute([$msg_id, $_SESSION['user_id']]);
                $current_count = $count_stmt->fetchColumn();

                if ($current_count < 3) {
                    $stmt = $pdo->prepare("INSERT INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)");
                    $stmt->execute([$msg_id, $_SESSION['user_id'], $emoji]);
                    
                    // SIGNAL: Update immediately
                    $pdo->prepare("INSERT INTO chat_signals (signal_type, signal_val) VALUES ('REACT', ?)")->execute([$msg_id]);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Catch Throwable to handle Fatal Errors (PHP 7+) and Exceptions
}

go_back();
?>