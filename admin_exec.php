<?php
session_start();
require 'db_config.php';

// 1. AUTH CHECK
if (!isset($_SESSION['fully_authenticated'])) { die("AUTH REQUIRED"); }

// 2. FETCH PERMS
$s_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($s_stmt->fetchColumn() ?: '{}', true);

$req_ban  = $perms['perm_user_ban'] ?? 9;
$req_kick = $perms['perm_user_kick'] ?? 5;
$req_nuke = $perms['perm_user_nuke'] ?? 10;
$req_del  = $perms['perm_chat_delete'] ?? 5; // Re-using delete perm for Wipe
$my_rank  = $_SESSION['rank'] ?? 0;

// 3. CONFIRMATION HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirmed'])) {
    $action = '';
    $action_key = ''; // To preserve the button name

    if (isset($_POST['action_ban'])) { $action = 'BAN'; $action_key = 'action_ban'; }
    elseif (isset($_POST['action_kick'])) { $action = 'KICK'; $action_key = 'action_kick'; }
    elseif (isset($_POST['action_nuke'])) { $action = 'NUKE'; $action_key = 'action_nuke'; }
    elseif (isset($_POST['action_unban'])) { $action = 'UNBAN'; $action_key = 'action_unban'; }
    elseif (isset($_POST['action_wipe'])) { $action = 'WIPE_MESSAGES'; $action_key = 'action_wipe'; }
    elseif (isset($_POST['action_mute'])) { $action = 'MUTE'; $action_key = 'action_mute'; }
    elseif (isset($_POST['action_unmute'])) { $action = 'UNMUTE'; $action_key = 'action_unmute'; }
    elseif (isset($_POST['action_slow'])) { $action = 'SLOW_MODE'; $action_key = 'action_slow'; }
    elseif (isset($_POST['action_unslow'])) { $action = 'UNSLOW'; $action_key = 'action_unslow'; }
    else { header("Location: users_online.php"); exit; }

    $target = htmlspecialchars($_POST['target_name'] ?? 'Target');
    $color  = (in_array($action, ['UNBAN', 'UNMUTE', 'UNSLOW'])) ? '#6a9c6a' : '#e06c75';
    if ($action === 'WIPE_MESSAGES') $color = '#56b6c2';
    
    ?>
    <!DOCTYPE html>
    <html>
    <body style="background:#0d0d0d; color:#ccc; font-family:monospace; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100vh; margin:0;">
        <div style="border:1px solid <?= $color ?>; padding:20px; background:#161616; width:80%; max-width:400px; text-align:center;">
            <h2 style="color:<?= $color ?>; margin-top:0;">CONFIRM <?= $action ?></h2>
            <p>Are you sure you want to apply <strong><?= $action ?></strong> to user:</p>
            <h3 style="background:#000; padding:10px; border:1px solid #333;"><?= $target ?></h3>
            
            <form method="POST" style="margin-top:20px; display:flex; flex-direction:column; gap:10px;">
                <?php foreach($_POST as $k => $v): 
                    // Don't duplicate the action button itself, we handle it via hidden input below
                    if($k !== $action_key) echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
                endforeach; ?>
                
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="<?= $action_key ?>" value="1"> <?php if($action === 'BAN'): ?>
                    <input type="text" name="ban_reason" placeholder="Reason (Optional)" style="background:#000; color:#fff; border:1px solid #333; padding:5px; width:100%; box-sizing:border-box;">
                <?php endif; ?>

                <div style="display:flex; justify-content:center; gap:10px; margin-top:10px;">
                    <button type="submit" style="background:<?= $color ?>; color:#000; border:none; padding:10px 20px; font-weight:bold; cursor:pointer;">YES, EXECUTE</button>
                    <a href="<?= htmlspecialchars($_POST['return_to'] ?? 'mod_panel.php') ?>" style="display:inline-block; padding:10px 20px; background:#333; color:#fff; text-decoration:none; font-size:0.8rem;">CANCEL</a>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 4. EXECUTION HANDLER (Confirmed = 1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmed'])) {
    
    $tid = (int)($_POST['target_id'] ?? 0);
    $return = $_POST['return_to'] ?? 'mod_panel.php';
    
    // LOG IDENTIFIER
    $log_ident = "SID:" . substr(session_id(), 0, 6) . "..";

    // --- KICK ---
    if (isset($_POST['action_kick'])) {
        if ($my_rank < $req_kick) die("ACCESS DENIED");
        $pdo->prepare("UPDATE users SET force_logout = 1 WHERE id = ?")->execute([$tid]);
        $act = "Kicked User #$tid";
    }

    // --- BAN ---
    if (isset($_POST['action_ban'])) {
        if ($my_rank < $req_ban) die("ACCESS DENIED");
        $reason = trim($_POST['ban_reason'] ?? '');
        if(empty($reason)) $reason = "Violated System Protocols";
        
        $pdo->prepare("UPDATE users SET is_banned = 1, force_logout = 1, ban_reason = ? WHERE id = ?")->execute([$reason, $tid]);
        $act = "BANNED User #$tid (Reason: $reason)";
    }

    // --- UNBAN ---
    if (isset($_POST['action_unban'])) {
        if ($my_rank < $req_ban) die("ACCESS DENIED");
        $pdo->prepare("UPDATE users SET is_banned = 0 WHERE id = ?")->execute([$tid]);
        $act = "UNBANNED User #$tid";
    }

    // --- MUTE / UNMUTE ---
    if (isset($_POST['action_mute'])) {
        if ($my_rank < $req_kick) die("ACCESS DENIED"); // Uses Kick perm
        $pdo->prepare("UPDATE users SET is_muted = 1 WHERE id = ?")->execute([$tid]);
        $act = "MUTED User #$tid";
    }
    if (isset($_POST['action_unmute'])) {
        if ($my_rank < $req_kick) die("ACCESS DENIED");
        $pdo->prepare("UPDATE users SET is_muted = 0 WHERE id = ?")->execute([$tid]);
        $act = "UNMUTED User #$tid";
    }

    // --- SLOW MODE OVERRIDE (15s Default) ---
    if (isset($_POST['action_slow'])) {
        if ($my_rank < $req_kick) die("ACCESS DENIED");
        $pdo->prepare("UPDATE users SET slow_mode_override = 15 WHERE id = ?")->execute([$tid]);
        $act = "SLOWED (15s) User #$tid";
    }
    if (isset($_POST['action_unslow'])) {
        if ($my_rank < $req_kick) die("ACCESS DENIED");
        $pdo->prepare("UPDATE users SET slow_mode_override = 0 WHERE id = ?")->execute([$tid]);
        $act = "REMOVED SLOW on User #$tid";
    }

    // --- WIPE ---
    if (isset($_POST['action_wipe'])) {
        if ($my_rank < $req_del) die("ACCESS DENIED");
        $pdo->prepare("DELETE FROM chat_messages WHERE user_id = ?")->execute([$tid]);
        $pdo->prepare("INSERT INTO chat_signals (signal_type) VALUES ('PURGE')")->execute();
        $act = "WIPED MESSAGES User #$tid";
    }

    // --- NUKE ---
    if (isset($_POST['action_nuke'])) {
        if ($my_rank < $req_nuke) die("ACCESS DENIED");
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM chat_messages WHERE user_id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM posts WHERE user_id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM chat_reactions WHERE user_id = ?")->execute([$tid]);
        $act = "NUKED User #$tid";
    }

    // LOG & REDIRECT
    if (isset($act)) {
        $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['username'], $act, $log_ident]);
    }

    header("Location: $return");
    exit;
}
?>