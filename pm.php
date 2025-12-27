<?php
session_start();
require 'db_config.php';
require_once 'bbcode.php';

// --- SECURITY: NO GUESTS ---
if (!isset($_SESSION['fully_authenticated']) || (isset($_SESSION['is_guest']) && $_SESSION['is_guest'])) { 
    header("Location: chat.php"); exit; 
}

// 1. IDENTITY & PERMISSIONS
$my_id = $_SESSION['user_id'];
$my_type = 'user'; // PMs currently User-only

// Fetch Permissions
$stmt_p = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($stmt_p->fetchColumn() ?: '{}', true);
$req_pm = $perms['perm_send_pm'] ?? 1;

// 2. TARGET RESOLUTION
$target_id = isset($_GET['to']) ? (int)$_GET['to'] : 0;
$target_type = $_GET['type'] ?? 'user'; 

// --- DATA FETCHING ---
$contacts = [];
$target_name = "Unknown";
$target_key = "";

if ($target_id) {
    // [ CONVERSATION MODE ]
    if ($target_type === 'user') {
        $stmt_u = $pdo->prepare("SELECT username, pgp_public_key FROM users WHERE id = ?");
        $stmt_u->execute([$target_id]);
        $res = $stmt_u->fetch();
        if ($res) {
            $target_name = $res['username'];
            $target_key  = $res['pgp_public_key'];
        }
    } else {
        // Fallback for Guest targets (if enabled later)
        $stmt_g = $pdo->prepare("SELECT guest_username FROM guest_tokens WHERE id = ?");
        $stmt_g->execute([$target_id]);
        $target_name = $stmt_g->fetchColumn() ?: "Guest_#$target_id";
    }
} else {
    // [ INBOX MODE ]
    // Fetch unique senders
    $sql = "
        SELECT 
            pm.sender_id as id,
            MAX(pm.created_at) as last_msg,
            COUNT(CASE WHEN pm.is_read = 0 THEN 1 END) as unread
        FROM private_messages pm
        WHERE pm.receiver_id = ? 
        GROUP BY pm.sender_id
        ORDER BY last_msg DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$my_id]);
    $raw_contacts = $stmt->fetchAll();
    
    foreach($raw_contacts as $c) {
        $stmt_u = $pdo->prepare("SELECT username, rank, chat_color FROM users WHERE id = ?");
        $stmt_u->execute([$c['id']]);
        $u = $stmt_u->fetch();
        
        if ($u) {
            $c['username'] = $u['username'];
            $c['rank'] = $u['rank'];
            $c['color'] = $u['chat_color'];
            $c['type'] = 'user';
        } else {
            $c['username'] = "Unknown/Deleted";
            $c['rank'] = 0;
            $c['color'] = '#444';
            $c['type'] = 'user'; 
        }
        $contacts[] = $c;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Secure Comms</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* --- CRITICAL LAYOUT ENGINE --- */
        html, body {
            height: 100%;
            width: 100%;
            margin: 0;
            padding: 0;
            background: transparent; /* [FIX] Allow wallpaper to show */
            overflow: hidden; 
            font-family: monospace;
        }

.layout-root {
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
            max-width: 1000px; 
            margin: 0 auto;
            border-left: 1px solid #222;
            border-right: 1px solid #222;
            background: transparent; /* [FIX] Fully clear to match Main Chat */
        }
        /* Fixed Top Bar */
        .layout-header {
            flex: 0 0 auto; 
            background: rgba(22, 22, 22, 0.95); /* [FIX] Transparent Header */
            border-bottom: 1px solid #333;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 50px; 
            box-sizing: border-box;
        }

        /* Flexible Content Area */
        .layout-content {
            flex: 1; 
            position: relative; 
            display: flex;
            flex-direction: column;
            min-height: 0; 
            background: transparent; /* [FIX] Inherit root transparency */
        }

        /* INBOX LIST SCROLL */
        .inbox-scroll {
            overflow-y: auto;
            height: 100%;
            padding: 20px;
        }

        /* CHAT IFRAME CONTAINER */
        .stream-wrapper {
            flex: 1; 
            position: relative; 
            min-height: 0; 
            background: rgba(0, 0, 0, 0.7); /* [FIX] Darker tint for chat messages */
        }

        .iframe-fullscreen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
            display: block;
            background: transparent; /* [FIX] Ensure iframes don't block bg */
        }

        /* INPUT AREA */
        .input-wrapper {
            flex: 0 0 105px; 
            border-top: 1px solid #333;
            background: rgba(17, 17, 17, 0.95); /* [FIX] Transparent Input Area */
            position: relative;
        }

        /* PGP DROPDOWN */
        details.pgp-top { background: #111; border-bottom: 1px solid #333; }
        details.pgp-top summary { 
            padding: 8px 15px; cursor: pointer; color: #666; font-size: 0.65rem; 
            background: #161616; user-select: none; outline: none;
        }
        details.pgp-top summary:hover { color: #6a9c6a; }
        .pgp-box { 
            width: 100%; height: 100px; background: #050505; color: #6a9c6a; 
            border: none; padding: 10px; font-family: monospace; font-size: 0.7rem; 
            resize: none; display: block; box-sizing: border-box;
        }

        /* LINKS */
        a.contact-row {
            display: flex; justify-content: space-between; align-items: center;
            background: #161616; border: 1px solid #333; 
            padding: 12px 15px; margin-bottom: 8px; text-decoration: none;
            transition: border-color 0.2s;
        }
        a.contact-row:hover { border-color: #6a9c6a; }
        .badge { background: #333; color: #ccc; padding: 2px 6px; font-size: 0.65rem; border-radius: 3px; }
        .unread { color: #e06c75; font-weight: bold; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>

<div class="layout-root">
    
    <div class="layout-header">
        <div style="display:flex; align-items:center; gap: 20px;">
            <a href="index.php" style="color:#888; text-decoration:none; font-weight:bold; letter-spacing:1px;">Placebo</a>
            <span style="color:#444; font-size:0.75rem;">// ENCRYPTED_LINK [USER]</span>
        </div>
        <div style="font-size:0.75rem;">
            <?php if($target_id): ?>
                <a href="pm.php" style="color:#e5c07b; margin-right:15px; text-decoration:none;">&lt; INBOX</a>
            <?php else: ?>
                <a href="pm.php" style="color:#6a9c6a; margin-right:15px; text-decoration:none;">[ REFRESH ]</a>
            <?php endif; ?>
            <a href="chat.php" style="color:#888; text-decoration:none;">[ CHAT ]</a>
        </div>
    </div>

    <div class="layout-content">
        
        <?php if($target_id): ?>
            <div style="flex: 0 0 auto; padding: 10px 15px; background: #111; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center;">
                <span style="color: #fff; font-weight: bold;">UPLINK: <span style="color: #6a9c6a;"><?= htmlspecialchars($target_name) ?></span></span>
                <span style="color: #444; font-size: 0.7rem;">E2E_OFF // OTR_ON</span>
            </div>

            <?php if($target_key): ?>
            <div style="flex: 0 0 auto;">
                <details class="pgp-top">
                    <summary>[ VIEW TARGET PGP IDENTITY ]</summary>
                    <textarea readonly class="pgp-box"><?= htmlspecialchars($target_key) ?></textarea>
                </details>
            </div>
            <?php endif; ?>

            <div class="stream-wrapper">
                <?php $wiped_flag = isset($_GET['wiped']) ? '&wiped=1' : ''; ?>
                <iframe name="pm_stream" class="iframe-fullscreen" src="pm_stream.php?to=<?= $target_id . $wiped_flag ?>"></iframe>
            </div>

            <div class="input-wrapper">
                <iframe name="pm_input" class="iframe-fullscreen" src="pm_input.php?to=<?= $target_id ?>"></iframe>
            </div>

        <?php else: ?>
            <div class="inbox-scroll">
                <h3 style="color:#d19a66; border-bottom:1px solid #333; padding-bottom:10px; margin-top:0;">ACTIVE FREQUENCIES</h3>
                
                <?php if(empty($contacts)): ?>
                    <div style="color:#444; padding:20px; text-align:center; border:1px dashed #333;">No encrypted sessions found.</div>
                <?php endif; ?>

                <?php foreach($contacts as $c): ?>
                    <a href="pm.php?to=<?= $c['id'] ?>&type=<?= $c['type'] ?>" class="contact-row">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="font-weight:bold; color:<?= $c['color'] ?: '#ccc' ?>;"><?= htmlspecialchars($c['username']) ?></span>
                            <?php if($c['rank'] > 0): ?>
                                <span class="badge">L<?= $c['rank'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if($c['unread'] > 0): ?>
                            <div class="unread">[ <?= $c['unread'] ?> UNREAD ]</div>
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