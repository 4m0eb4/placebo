<?php
session_start();
require 'db_config.php';

// 1. SECURITY: ADMINS ONLY (Rank 9+)
if (!isset($_SESSION['rank']) || $_SESSION['rank'] < 9) {
    header("Location: index.php");
    exit;
}

// 2. PRIVACY LOGGING: Generate Identity String (No IP)
$log_ident = "SID:" . substr(session_id(), 0, 8) . ".."; // Default: Partial Session ID

try {
    // Attempt to fetch Admin's PGP Fingerprint for the log
    $stmt_fp = $pdo->prepare("SELECT pgp_fingerprint FROM users WHERE id = ?");
    $stmt_fp->execute([$_SESSION['user_id']]);
    $fp = $stmt_fp->fetchColumn();
    
    if ($fp) {
        // Remove spaces and grab the last 4 characters
        $clean_fp = str_replace(' ', '', $fp);
        $log_ident .= " / FP:" . substr($clean_fp, -4);
    } else {
        $log_ident .= " / FP:NONE";
    }
} catch (Exception $e) {
    // Silent fail on FP fetch, keep minimal log
}

// 3. EXECUTE COMMANDS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- COMMAND: KICK USER ---
    if (isset($_POST['action_kick']) && isset($_POST['target_user_id'])) {
        $tid = (int)$_POST['target_user_id'];
        
        // Update user to force logout
        $stmt = $pdo->prepare("UPDATE users SET force_logout = 1 WHERE id = ?");
        $stmt->execute([$tid]);
        
        // Log Action
        $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['username'], "Kicked User #$tid", $log_ident]);
    }

    // --- COMMAND: BAN USER ---
    if (isset($_POST['action_ban']) && isset($_POST['target_user_id'])) {
        $tid = (int)$_POST['target_user_id'];
        
        // Update user: Ban AND Force Logout
        $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, force_logout = 1 WHERE id = ?");
        $stmt->execute([$tid]);
        
        // Log Action
        $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['username'], "BANNED User #$tid", $log_ident]);
    }

    // --- COMMAND: TERMINATE GUEST UPLINK ---
    if (isset($_POST['target_guest_name'])) {
        $gname = $_POST['target_guest_name'];
        
        // Revoke the token
        $stmt = $pdo->prepare("UPDATE guest_tokens SET status = 'revoked' WHERE guest_username = ?");
        $stmt->execute([$gname]);
        
        // Log Action
        $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['username'], "Terminated Guest: $gname", $log_ident]);
    }
}

// 4. RETURN TO MONITOR
// We redirect back to users_online.php so you can see the result immediately
header("Location: users_online.php");
exit;
?>