<?php
session_start();
require 'db_config.php';
require 'bbcode.php';

// Access Control: Block Guests
if (!isset($_SESSION['fully_authenticated']) || (isset($_SESSION['is_guest']) && $_SESSION['is_guest'])) { 
    header("Location: chat.php"); exit; 
}

// 1. IDENTITY RESOLUTION
// Determine if we are a Registered User or a Guest
$is_guest = isset($_SESSION['is_guest']) && $_SESSION['is_guest'];

if ($is_guest) {
    $my_id = $_SESSION['guest_token_id']; // Guests use Token ID
    $my_type = 'guest';
} else {
    $my_id = $_SESSION['user_id']; // Users use User ID
    $my_type = 'user';
}

// Permission Logic
$stmt_p = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($stmt_p->fetchColumn() ?: '{}', true);
$req_pm = $perms['perm_send_pm'] ?? 1;

// Target Logic
$target_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;
// Note: Currently assumes targets are Users (since Guests replying to Guests is rare/edge case).
// To support chatting with a guest, we would need a 'type' param in URL. Defaulting to 'user' target.
$target_type = $_GET['type'] ?? 'user'; 

// --- VIEW LOGIC ---
if ($target_id) {
    // [ CONVERSATION MODE ]
    
    // Fetch Target Details
    $target_name = "Unknown";
    $target_key  = "";
    
    if ($target_type === 'user') {
        $stmt_u = $pdo->prepare("SELECT username, pgp_public_key FROM users WHERE id = ?");
        $stmt_u->execute([$target_id]);
        $res = $stmt_u->fetch();
        if ($res) {
            $target_name = $res['username'];
            $target_key  = $res['pgp_public_key'];
        }
    } else {
        // Guest Target
        $stmt_g = $pdo->prepare("SELECT guest_username FROM guest_tokens WHERE id = ?");
        $stmt_g->execute([$target_id]);
        $target_name = $stmt_g->fetchColumn() ?: "Guest_#$target_id";
    }

} else {
    // [ INBOX MODE ]
    
    // We fetch distinct senders who have messaged US.
    // (Limitation: Without 'sender_type' in DB, we assume most senders are Users for now)
    $sql = "
        SELECT 
            pm.sender_id as id,
            MAX(pm.created_at) as last_msg,
            COUNT(CASE WHEN pm.is_read = 0 THEN 1 END) as unread
        FROM private_messages pm
        WHERE pm.receiver_id = ? AND pm.receiver_type = ?
        GROUP BY pm.sender_id
        ORDER BY last_msg DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$my_id, $my_type]);
    $raw_contacts = $stmt->fetchAll();
    
    // Hydrate Names
    $contacts = [];
    foreach($raw_contacts as $c) {
        // Fetch User Name
        $stmt_u = $pdo->prepare("SELECT username, rank FROM users WHERE id = ?");
        $stmt_u->execute([$c['id']]);
        $u = $stmt_u->fetch();
        
        if ($u) {
            $c['username'] = $u['username'];
            $c['rank'] = $u['rank'];
            $c['type'] = 'user';
        } else {
            // Fallback for Guest Senders (if ID doesn't match a user)
            $c['username'] = "Unknown/Guest";
            $c['rank'] = 0;
            $c['type'] = 'guest'; 
        }
        $contacts[] = $c;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Secure Comms</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pm-container { width: 100%; max-width: 1000px; margin: 0 auto; }
        .inbox-card { background: #080808; border: 1px solid #222; padding: 15px; margin-bottom: 15px; }
        
        /* PGP ACCORDION */
        details.pgp-top { background: #080808; border: 1px solid #333; border-top: none; margin-bottom: 20px; }
        details.pgp-top summary { 
            padding: 8px 15px; cursor: pointer; color: #666; font-size: 0.65rem; font-family: monospace; 
            background: #111; border-bottom: 1px solid #333; user-select: none;
        }
        details.pgp-top summary:hover { color: #6a9c6a; }
        details.pgp-top[open] summary { color: #6a9c6a; border-bottom: 1px solid #333; }
        .pgp-content { padding: 10px; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="margin:0; padding:0; height:100vh; overflow:hidden; display:flex; flex-direction:column;">

<div class="main-container" style="width: 100%; max-width: 1000px; margin: 0 auto; height:100%; display:flex; flex-direction:column; border-left:1px solid #222; border-right:1px solid #222;">
    <div class="nav-bar" style="flex: 0 0 auto; background: #161616; border-bottom: 1px solid #333; padding: 10px 20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <a href="index.php" class="term-logo">Placebo</a>
            <span style="color:#444; font-size:0.75rem; font-family:monospace;">// Secure_Comms [<?= strtoupper($my_type) ?>]</span>
        </div>
        <div style="font-size:0.75rem; font-family:monospace;">
            <?php if($target_id): ?>
                <a href="pm.php" style="color:#e5c07b; margin-right:15px; text-decoration:none;">&lt; INBOX</a>
            <?php else: ?>
                <a href="pm.php" style="color:#6a9c6a; margin-right:15px; text-decoration:none;">[ REFRESH ]</a>
            <?php endif; ?>
            <a href="chat.php" style="color:#888; text-decoration:none;">[ CHAT ]</a>
        </div>
    </div>

    <div class="content-area" style="flex: 1; background: #0d0d0d; display:flex; flex-direction:column; min-height:0; overflow:hidden;">
        
        <?php if($target_id): ?>
            <div style="padding:15px; border-bottom:1px solid #333; background:#111; display:flex; justify-content:space-between; align-items:center;">
                <span style="color:#fff; font-weight:bold;">UPLINK: <?= htmlspecialchars($target_name) ?></span>
                <span style="color:#444; font-size:0.7rem; font-family:monospace;">SECURE_V2 // NO_JS</span>
            </div>

            <?php if($target_key): ?>
            <details class="pgp-top">
                <summary>[ VIEW TARGET PGP IDENTITY ]</summary>
                <div class="pgp-content">
                     <textarea readonly class="pgp-box" style="height:120px; width:100%; box-sizing:border-box; background:#050505; color:#444; border:1px solid #222; font-family:monospace; padding:10px; display:block;"><?= htmlspecialchars($target_key) ?></textarea>
                </div>
            </details>
            <?php endif; ?>

            <div style="flex:1; background:#0d0d0d; position:relative; min-height:0;">
                <?php $wiped_flag = isset($_GET['wiped']) ? '&wiped=1' : ''; ?>
                <iframe name="pm_stream" src="pm_stream.php?to=<?= $target_id . $wiped_flag ?>" style="position:absolute; top:0; left:0; width:100%; height:100%; border:none;"></iframe>
            </div>

            <div style="height:105px; min-height:105px; border-top:1px solid #333; background:#111; flex-shrink:0; overflow:hidden;">
                <iframe name="pm_input" src="pm_input.php?to=<?= $target_id ?>" style="width:100%; height:100%; border:none; display:block;"></iframe>
            </div>

        <?php else: ?>
            <div style="padding:20px; overflow-y:auto;">
                <h3 style="color:#d19a66; border-bottom:1px solid #333; padding-bottom:10px;">ACTIVE FREQUENCIES</h3>
                <?php if(empty($contacts)): ?>
                    <div style="color:#444;">No prior communications.</div>
                <?php endif; ?>

                <?php foreach($contacts as $c): ?>
                    <a href="pm.php?to=<?= $c['id'] ?>&type=<?= $c['type'] ?>" style="display:block; background:#161616; padding:12px; margin-bottom:8px; border:1px solid #333; text-decoration:none; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <span style="color:#fff; font-weight:bold;"><?= htmlspecialchars($c['username']) ?></span>
                            <span class="badge" style="margin-left:5px;">L<?= $c['rank'] ?></span>
                        </div>
                        <?php if($c['unread'] > 0): ?>
                            <div style="color:#e06c75; font-weight:bold; font-size:0.7rem;">[ <?= $c['unread'] ?> UNREAD ]</div>
                        <?php else: ?>
                            <div style="color:#444; font-size:0.7rem;">[ OPEN ]</div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>