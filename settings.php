<?php
session_start();

if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}

require 'db_config.php';
require 'bbcode.php';

// Security Check
if (!isset($_SESSION['fully_authenticated']) || $_SESSION['fully_authenticated'] !== true) {
    header("Location: login.php"); exit;
}

// --- SAFE USER FETCH (Fixes 500 Error) ---
$user = [];
try {
    // Try full fetch
    $stmt = $pdo->prepare("SELECT username, rank, pgp_fingerprint, pgp_public_key, created_at, chat_color, show_online, user_status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    // Fallback if columns are missing
    $stmt = $pdo->prepare("SELECT username, rank, pgp_fingerprint, pgp_public_key, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
}

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- ACTION: REVOKE GUEST ---
    if (isset($_POST['revoke_id'])) {
        try {
            $rid = (int)$_POST['revoke_id'];
            $stmt_rev = $pdo->prepare("UPDATE guest_tokens SET status='revoked' WHERE id=? AND created_by=?");
            $stmt_rev->execute([$rid, $_SESSION['user_id']]);
            // Redirect back to list immediately to refresh view
            header("Location: users_online.php"); 
            exit;
        } catch (Exception $e) { $msg = "DB Error: Token table invalid."; }
    }
    // --- ACTION: PURGE EXPIRED TOKENS ---
    if (isset($_POST['purge_tokens'])) {
        try {
            $stmt_purge = $pdo->query("DELETE FROM guest_tokens WHERE expires_at < NOW()");
            $msg = "PURGE COMPLETE.";
        } catch (Exception $e) { $msg = "DB Error: Token table invalid."; }
    }
    
    // --- ACTION: UPDATE SETTINGS ---
    $new = $_POST['new_pass'] ?? '';
    $cnf = $_POST['confirm_pass'] ?? '';
    
    // 1. Password Update
    if ($new && $cnf) {
        if ($new !== $cnf) {
            $msg = "ERROR: Passwords do not match.";
        } elseif (strlen($new) < 12) {
            $msg = "ERROR: Password too short (min 12 chars).";
        } else {
            $hash = password_hash($new, PASSWORD_ARGON2ID);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
            $msg = "SUCCESS: Password Updated.";
        }
    }
    
    // 2. PGP Update
    if (isset($_POST['pgp_key']) && trim($_POST['pgp_key']) !== '') {
        $new_pgp = trim($_POST['pgp_key']);
        $pdo->prepare("UPDATE users SET pgp_public_key = ? WHERE id = ?")->execute([$new_pgp, $_SESSION['user_id']]);
        $user['pgp_public_key'] = $new_pgp;
        $msg .= " PGP Key Updated.";
    }

    // 3. Color Update
    if (isset($_POST['chat_color'])) {
        $new_color = $_POST['chat_color'];
        if (preg_match('/^#[a-f0-9]{6}$/i', $new_color)) {
            try {
                $pdo->prepare("UPDATE users SET chat_color = ? WHERE id = ?")->execute([$new_color, $_SESSION['user_id']]);
                $user['chat_color'] = $new_color; 
                $msg .= " Color Saved.";
            } catch (Exception $e) {}
        }
    }

    // 4. Status Update (VIP+)
    if ($user['rank'] >= 5 && isset($_POST['user_status'])) {
        try {
            $status = substr(trim($_POST['user_status']), 0, 30);
            $visible = isset($_POST['show_online']) ? 1 : 0;
            $pdo->prepare("UPDATE users SET user_status = ?, show_online = ? WHERE id = ?")->execute([$status, $visible, $_SESSION['user_id']]);
            $user['user_status'] = $status;
            $user['show_online'] = $visible;
            $msg .= " Status Updated.";
        } catch (Exception $e) {}
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Settings</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .settings-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .stat-box { background: var(--panel-bg); border: 1px solid var(--border-color); padding: 15px; }
        .stat-label { font-size: 0.7rem; color: #666; display: block; margin-bottom: 5px; }
        .stat-value { color: var(--accent-primary); font-family: monospace; }
        .section-head { border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px; color: var(--text-main); }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper" style="width: 700px;">
    <div class="terminal-header">
        <div>
            <a href="index.php" class="term-logo">Placebo</a>
            <span style="color:#333; font-family:monospace; font-weight:bold; margin-left:5px;">// Config</span>
        </div>
        <a href="index.php" style="color:#666; font-size:0.7rem;">&lt; RETURN</a>
    </div>

    <div style="padding: 25px;">
        <form method="POST">
            <h3 class="section-head">IDENTITY PARAMETERS</h3>
            <div class="settings-grid">
                <div class="stat-box">
                    <span class="stat-label">CLEARANCE LEVEL</span>
                    <span class="stat-value">RANK <?= $user['rank'] ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">REGISTRATION DATE</span>
                    <span class="stat-value"><?= $user['created_at'] ?></span>
                </div>
                <?php 
                    $pgp_locked = !isset($_GET['unlock_pgp']); 
                    $lock_style = $pgp_locked 
                        ? "background: #1a0505; color: #e06c75; border: 1px dashed #e06c75;" 
                        : "background: #080808; color: #ccc; border: 1px solid #333;";
                ?>
                <div class="input-group" style="grid-column: span 2; margin-bottom:0;">
                    <label>PGP FINGERPRINT</label>
                    <input type="text" name="pgp_fingerprint" value="<?= htmlspecialchars($user['pgp_fingerprint']) ?>" 
                           style="width:100%; padding:10px; font-family:monospace; <?= $lock_style ?>" 
                           <?= $pgp_locked ? 'readonly' : '' ?>>
                </div>
                <div class="input-group" style="grid-column: span 2;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <label>PGP PUBLIC KEY</label>
                        <?php if($pgp_locked): ?>
                            <a href="?unlock_pgp=1" style="font-size:0.6rem; color:#e06c75; border:1px solid #e06c75; padding:2px 6px;">[ UNLOCK ]</a>
                        <?php else: ?>
                            <span style="font-size:0.6rem; color:#6a9c6a;">[ EDIT MODE ACTIVE ]</span>
                        <?php endif; ?>
                    </div>
                    <textarea name="pgp_key" class="pgp-box" style="height:120px; width:100%; <?= $lock_style ?>" <?= $pgp_locked ? 'readonly' : '' ?>><?= htmlspecialchars($user['pgp_public_key']) ?></textarea>
                </div>
            </div>

            <h3 class="section-head" style="margin-top: 30px; color: var(--accent-secondary);">SECURITY UPDATE</h3>
            
            <div class="input-group">
                <label>CHANGE PASSWORD</label>
                <input type="password" name="new_pass" placeholder="New Password..." style="background: #080808;">
            </div>
            <div class="input-group">
                <label>CONFIRM PASSWORD</label>
                <input type="password" name="confirm_pass" placeholder="Verify New Password..." style="background: #080808;">
            </div>

            <div class="input-group">
                <label>CHAT FREQUENCY COLOR</label>
                <div style="display:flex; gap:10px;">
                    <input type="color" name="chat_color" value="<?= htmlspecialchars($user['chat_color'] ?? '#888888') ?>" style="height:40px; width:60px; padding:0; background:none; border:none;">
                    <div style="font-size:0.7rem; color:#666; display:flex; align-items:center;">
                        Sets your username color.
                    </div>
                </div>
            </div>

            <?php if ($user['rank'] >= 5): ?>
            <h3 class="section-head" style="margin-top: 30px; color: #98c379;">STATUS PROTOCOLS</h3>
            <div style="background:#111; border:1px solid #333; padding:15px;">
                <div class="input-group" style="margin-bottom:10px;">
                    <label>CUSTOM STATUS</label>
                    <input type="text" name="user_status" value="<?= htmlspecialchars($user['user_status'] ?? '') ?>" placeholder="e.g. 💀 Lurking..." maxlength="30">
                </div>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.65rem; color:#555;">
                    <input type="checkbox" name="show_online" value="1" <?= ($user['show_online'] ?? 1) ? 'checked' : '' ?>>
                    BROADCAST ONLINE SIGNAL
                </label>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn-primary" style="margin-top:15px;">UPDATE PROFILE</button>
            
            
            <h3 class="section-head" style="margin-top: 40px; color: #e5c07b;">ACTIVE GUEST UPLINKS</h3>
            
            <?php
            // Safe Guest Fetch
            $my_guests = [];
            try {
                $stmt = $pdo->prepare("SELECT * FROM guest_tokens WHERE created_by = ? AND status = 'active' ORDER BY created_at DESC");
                $stmt->execute([$_SESSION['user_id']]);
                $my_guests = $stmt->fetchAll();
            } catch(Exception $e) {}
            ?>

            <?php if (empty($my_guests)): ?>
                <div style="color: #555; font-size: 0.7rem; font-style: italic; margin-bottom: 10px;">No active guest sessions.</div>
            <?php else: ?>
                <div style="display: grid; gap: 10px; margin-bottom: 20px;">
                <?php foreach($my_guests as $g): ?>
                    <?php 
                        $remain = 0;
                        if(isset($g['expires_at'])) {
                            $remain = strtotime($g['expires_at']) - time();
                        }
                        $hours_left = ($remain > 0) ? round($remain/3600, 1) : 0;
                        $color = ($hours_left < 1) ? '#e06c75' : '#6a9c6a';
                    ?>
                    <div style="background: #111; border: 1px solid #333; padding: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 0.7rem;">
                            <div style="color: #ccc; font-weight: bold; font-family:monospace;">TOKEN: <?= $g['token'] ?></div>
                            <div style="color: #666; margin-top:3px;">
                                REMAINING: <span style="color:<?= $color ?>"><?= $hours_left ?> HOURS</span>
                            </div>
                        </div>
                        <button type="submit" name="revoke_id" value="<?= $g['id'] ?>" onclick="return confirm('TERMINATE UPLINK?');" class="btn-primary" style="width: auto; padding: 5px 10px; font-size: 0.6rem; background: #220505; border-color: #e06c75; color: #e06c75;">
                            TERMINATE
                        </button>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="border-top: 1px dashed #333; padding-top: 15px; margin-top: 10px;">
                <button type="submit" name="purge_tokens" class="btn-primary" style="width:100%; padding:10px; font-size:0.7rem; background:#0d0d0d; border-color:#444; color:#666;">[ PURGE EXPIRED / DEAD TOKENS ]</button>
            </div>

        </form>
        <?php if($msg): ?><div style="margin-top:15px; text-align:center; font-size:0.8rem;" class="<?= strpos($msg, 'ERROR')!==false ? 'error' : 'success' ?>"><?= $msg ?></div><?php endif; ?>
    </div>
</div>
</body>
</html>