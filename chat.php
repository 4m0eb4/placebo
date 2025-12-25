<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// Determine Identity
$is_guest = $_SESSION['is_guest'] ?? false;
$username = $_SESSION['username'];
$rank = $_SESSION['rank'] ?? 0;

// 1. CHANNEL SWITCHING & SECURITY GATEWAY
if (isset($_GET['set_channel'])) {
    $target_cid = (int)$_GET['set_channel'];
    $err = "";
    
    try {
        $stmt_ch = $pdo->prepare("SELECT id, name, read_rank, password FROM chat_channels WHERE id = ?");
        $stmt_ch->execute([$target_cid]);
        $ch_data = $stmt_ch->fetch();

        if (!$ch_data) { header("Location: chat.php"); exit; }

        // A. Rank Check
        if ($rank < $ch_data['read_rank']) {
            $err = "INSUFFICIENT CLEARANCE (Rank {$ch_data['read_rank']}+ Required)";
        }
        
        // B. Password Check
        // If channel has password AND user hasn't authed for this channel yet
        if (empty($err) && !empty($ch_data['password'])) {
            if (!isset($_SESSION['chan_auth'][$target_cid])) {
                // Handle Password Submission
                if (isset($_POST['chan_pass'])) {
                    if (password_verify($_POST['chan_pass'], $ch_data['password'])) {
                        $_SESSION['chan_auth'][$target_cid] = true;
                    } else {
                        $err = "INVALID DECRYPTION KEY";
                    }
                } else {
                    // Show Gatekeeper Form
                    ?>
                    <!DOCTYPE html>
                    <html><head><link rel="stylesheet" href="style.css"></head>
                    <body style="background:#0d0d0d; display:flex; align-items:center; justify-content:center; height:100vh; font-family:monospace;">
                        <div class="login-box" style="border-color:#e06c75; max-width:400px; width:100%;">
                            <div class="login-header" style="background:#1a0505; color:#e06c75;">SECURE FREQUENCY DETECTED</div>
                            <form method="POST" style="padding:20px;">
                                <p style="color:#ccc; margin-top:0;">CHANNEL: <strong>#<?= htmlspecialchars($ch_data['name']) ?></strong> is encrypted.</p>
                                <div class="input-group">
                                    <label>Decryption Key (Password)</label>
                                    <input type="password" name="chan_pass" autofocus required>
                                </div>
                                <button type="submit" class="btn-primary" style="width:100%;">AUTHENTICATE</button>
                                <a href="chat.php" style="display:block; text-align:center; margin-top:15px; color:#666; text-decoration:none;">[ CANCEL ]</a>
                            </form>
                        </div>
                    </body></html>
                    <?php
                    exit;
                }
            }
        }

        // C. Access Granted
        if (empty($err)) {
            $_SESSION['active_channel'] = $target_cid;
            header("Location: chat.php"); exit;
        }

    } catch (Exception $e) { $err = "System Error"; }

    // D. Access Denied Screen
    if ($err) {
        die("<!DOCTYPE html><html><body style='background:#000; color:#e06c75; font-family:monospace; display:flex; height:100vh; align-items:center; justify-content:center;'>
            <div style='border:1px solid #e06c75; padding:20px; text-align:center;'>
                <h2 style='margin-top:0;'>ACCESS DENIED</h2>
                <p>$err</p>
                <a href='chat.php' style='color:#fff;'>[ RETURN ]</a>
            </div>
        </body></html>");
    }
}

// Default Channel
if (!isset($_SESSION['active_channel'])) $_SESSION['active_channel'] = 1;
$active_cid = $_SESSION['active_channel'];

// Fetch Channel Name for Display
$active_c_name = "UNK";
try {
    $stmt_cn = $pdo->prepare("SELECT name FROM chat_channels WHERE id = ?");
    $stmt_cn->execute([$active_cid]);
    $active_c_name = $stmt_cn->fetchColumn() ?: "Public";
} catch(Exception $e) {}


// RE-CHECK MUTE STATUS (REAL-TIME)
$is_muted = $_SESSION['is_muted'] ?? false;
try {
    if ($is_guest) {
        $m_stmt = $pdo->prepare("SELECT is_muted FROM guest_tokens WHERE id = ?");
        $m_stmt->execute([$_SESSION['guest_token_id']]);
        if($m_stmt->fetchColumn()) $is_muted = true;
    } else {
        $m_stmt = $pdo->prepare("SELECT is_muted FROM users WHERE id = ?");
        $m_stmt->execute([$_SESSION['user_id']]);
        if($m_stmt->fetchColumn()) $is_muted = true;
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html style="height: 100%; overflow: hidden;">
<head>
    <title>.placebo.</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { 
            margin: 0 !important; 
            padding: 0 !important; 
            width: 100vw !important; 
            height: 100vh !important;
            display: flex !important; 
            flex-direction: column !important; 
            align-items: stretch !important; 
            background: #0d0d0d !important; 
            overflow: hidden !important;
        }
        .nav-bar { flex-shrink: 0; z-index: 10; border-bottom: 1px solid #333; width: 100%; box-sizing: border-box; }
        .chat-container { flex: 1; display: flex; flex-direction: column; position: relative; overflow: hidden; width: 100%; }
        
        .chat-options {
            height: 35px;
            background: #111;
            border-top: 1px solid #222;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 15px;
            font-family: monospace;
            font-size: 0.7rem;
            color: #444;
        }
        .opt-btn { cursor: pointer; color: #555; text-decoration: none; }
        .opt-btn:hover { color: #888; }
        .opt-btn-green { color: #5a8a5a; }
        .opt-btn-green:hover { color: #6a9c6a; }
        
        .status-buffer { height: 30px; background: #0d0d0d; border-top: 1px solid #1a1a1a; flex-shrink: 0; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>

    <input type="checkbox" id="channels-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 400px; max-width: 500px; border-color: #56b6c2;">
            <div class="modal-header" style="border-color: #56b6c2;">
                <span style="color:#56b6c2;">FREQUENCY SELECTOR</span>
                <label for="channels-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="channels_list.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="invite-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 250px; max-width: 350px;">
            <div class="modal-header">
                <span>INVITE SYSTEM</span>
                <label for="invite-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="chat_invite.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="rules-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 550px; max-width: 600px;">
            <div class="modal-header">
                <span>SYSTEM PROTOCOLS</span>
                <label for="rules-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="rules.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="help-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 600px; max-width: 720px;">
            <div class="modal-header">
                <span>MANUAL</span>
                <label for="help-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="help_bbcode.php?modal=1" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="users-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 450px;">
            <div class="modal-header">
                <span>ACTIVE SIGNALS</span>
                <label for="users-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="users_online.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="directory-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 600px; max-width: 850px;">
            <div class="modal-header">
                <span>USER DIRECTORY</span>
                <label for="directory-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="users_list.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="mod-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 500px; max-width: 550px; border-color: #e06c75;">
            <div class="modal-header" style="border-bottom-color: #e06c75;">
                <span style="color: #e06c75;">USER MODERATION</span>
                <label for="mod-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe name="mod_frame" src="mod_panel.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <input type="checkbox" id="upload-modal-toggle" class="modal-toggle">
    <div class="modal-overlay">
        <div class="modal-box" style="height: 400px; max-width: 500px; border-color: #6a9c6a;">
            <div class="modal-header" style="border-color: #6a9c6a;">
                <span style="color: #6a9c6a;">UPLOAD INTERFACE</span>
                <label for="upload-modal-toggle" class="modal-close">[ CLOSE ]</label>
            </div>
            <div class="modal-content">
                <iframe src="upload_modal.php" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
<div class="nav-bar" style="background: #161616; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a> 
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Chat</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <?php
                // 1. LINKS CHECK (Dynamic Rank)
                $l_req = 5; 
                try {
                    $stmt_l = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'links_min_rank'");
                    if ($row_l = $stmt_l->fetch()) $l_req = (int)$row_l['setting_value'];
                } catch (Exception $e) {}

                if (($rank ?? 0) >= $l_req):
                ?>
                    <a href="links.php" style="color:#888; margin-right:10px; text-decoration:none;">[ LINKS ]</a>
                <?php endif; ?>

                <?php 
                // 2. DATA CHECK (Dynamic Rank)
                $d_req = 5; 
                try {
                    $stmt_d = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'gallery_min_rank'");
                    if ($row_d = $stmt_d->fetch()) $d_req = (int)$row_d['setting_value'];
                } catch (Exception $e) {}
                
                if(($rank ?? 0) >= $d_req): 
                ?>
                    <a href="gallery.php" target="_blank" style="color:#888; margin-right:10px; text-decoration:none;">[ DATA ]</a>
                <?php endif; ?>

                <a href="games.php" target="_blank" style="color:#888; text-decoration:none;">[ GAMES ]</a>
            </div>
        </div>
        
        <div class="nav-links" style="font-size: 0.75rem; font-family: monospace;">
             <?php if (!$is_guest): ?>
                <?php if($rank >= 9): ?>
                    <a href="admin_dash.php" style="color: var(--accent-secondary); margin-right:15px;">[ ADMIN ]</a>
                <?php endif; ?>
                <a href="settings.php" style="margin-right:15px; color:#d19a66; text-decoration:none;">( <?= htmlspecialchars($username) ?> )</a>
                <a href="logout.php">LOGOUT</a>
            <?php else: ?>
                <input type="checkbox" id="guest-modal-toggle" class="modal-toggle">
                <div class="modal-overlay">
                    <div class="modal-box" style="width: 100%; max-width: 340px; height: 200px; border-color: var(--accent-secondary);">
                        <div class="modal-header" style="border-color: var(--accent-secondary);">
                            <span>GUEST_CONFIG // <?= htmlspecialchars($username) ?></span>
                            <label for="guest-modal-toggle" class="modal-close">[ CLOSE ]</label>
                        </div>
                        <div class="modal-content">
                            <iframe src="guest_settings.php" style="width:100%; height:100%; border:none;"></iframe>
                        </div>
                    </div>
                </div>
                <label for="guest-modal-toggle" style="margin-right:15px; color: var(--accent-secondary); cursor:pointer;">( <?= htmlspecialchars($username) ?> )</label>
                <a href="logout.php" style="color: var(--accent-alert);">[ EXIT ]</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="chat-container">
        <iframe name="chat_stream" src="chat_stream.php" style="flex: 1; border: none; width: 100%; display:block;"></iframe>

        <div style="position: relative; height: 145px; background: #0d0d0d; border-top: 1px solid #222; flex-shrink: 0; display:flex; flex-direction:column;">
            
            <label for="help-modal-toggle" style="
                position: absolute; 
                display: <?= ($is_chat_locked || ($is_muted ?? false)) ? 'none' : 'flex' ?>; 
                top: 24px; left: 5px; 
                width: 40px; height: 28px; /* Matches Input Height */
                background: #111; /* Matches Input BG */
                color: #6a9c6a; 
                border: 1px solid #333; 
                display: flex; align-items: center; justify-content: center; 
                font-family: monospace; font-weight: bold; cursor: pointer; 
                z-index: 50;
                font-size: 1rem;" title="Open Manual (Modal)">
                [?]
            </label>

            <div style="flex: 1; overflow:hidden;">
                <iframe name="chat_input" src="chat_input.php" style="width: 100%; height:100%; border: none; display:block;"></iframe>
            </div>

            <div class="chat-options">
                <?php
                // FETCH CONFIG & PERMS
                $conf_stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('permissions_config', 'chat_locked')");
                $settings_map = [];
                while($row = $conf_stmt->fetch()) { $settings_map[$row['setting_key']] = $row['setting_value']; }
                
                $mp_conf = json_decode($settings_map['permissions_config'] ?? '{}', true);
                $is_chat_locked = ($settings_map['chat_locked'] ?? '0') === '1';
                $is_muted = $_SESSION['is_muted'] ?? false; 
                
                // Fetch Upload Rank (Default 5)
                $req_upload = 5;
                try {
                     $urs = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='upload_min_rank'");
                     if($r = $urs->fetch()) $req_upload = (int)$r['setting_value'];
                } catch(Exception $e){}

                $req_mod = $mp_conf['perm_view_mod_panel'] ?? 9; 
                ?>
                <span>OPTIONS:</span>
                
                <label for="channels-modal-toggle" class="opt-btn" style="color:#56b6c2; font-weight:bold;">[ #<?= htmlspecialchars($active_c_name) ?> ]</label>

                <?php if (!$is_chat_locked && !$is_muted): ?>
                    <a href="help_bbcode.php" target="_blank" class="opt-btn" title="Open BBCode Manual">{bb}</a>
                <?php endif; ?>
                
                <label for="rules-modal-toggle" class="opt-btn">[ RULES ]</label>
                <label for="users-modal-toggle" class="opt-btn">[ ONLINE USERS ]</label>
                
                <?php 
                // Fetch dynamic permission requirement (re-using existing config fetch if available, or defaulting)
                $req_dir_view = $mp_conf['perm_view_directory'] ?? 3; 
                ?>

                <?php if ($rank >= $req_dir_view): ?>
                    <label for="directory-modal-toggle" class="opt-btn" style="color:#d19a66;">[ DIRECTORY ]</label>
                <?php endif; ?>
                
                

                <?php if (!$is_guest): ?>
                    <?php if ($rank >= 5): ?>
                        <label for="invite-modal-toggle" class="opt-btn opt-btn-green">[ INVITE ]</label>
                    <?php endif; ?>
                    
                    <?php if ($rank >= $req_upload): ?>
                        <label for="upload-modal-toggle" class="opt-btn" style="color:#6a9c6a; cursor:pointer;">[ UPLOAD ]</label>
                    <?php endif; ?>
                <?php endif; ?>
                
                <a href="chat_input.php" target="chat_input" class="opt-btn">[ REFRESH ]</a>

                <span style="margin-left:auto; color:#333;">SECURE_V2</span>
            </div>

            <div class="status-buffer"></div>
        </div>
    </div>

</body>
</html>