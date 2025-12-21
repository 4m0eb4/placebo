<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// Determine Identity
$is_guest = $_SESSION['is_guest'] ?? false;
$username = $_SESSION['username'];
$rank = $_SESSION['rank'] ?? 0;
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

    <div class="nav-bar" style="background: #161616; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a> 
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Chat</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <?php if (!$is_guest): ?>
                    <a href="links.php" style="color:#888; margin-right:10px; text-decoration:none;">[ LINKS ]</a>
                    <a href="games.php" style="color:#888; text-decoration:none;">[ GAMES ]</a>
                <?php endif; ?>
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

        <div style="height: 115px; background: #0d0d0d; border-top: 1px solid #222; flex-shrink: 0; display:flex; flex-direction:column;">
            
            <div style="flex: 1; overflow:hidden;">
                <iframe name="chat_input" src="chat_input.php" style="width: 100%; height:100%; border: none; display:block;"></iframe>
            </div>

            <div class="chat-options">
                <span>OPTIONS:</span>
                
                <label for="users-modal-toggle" class="opt-btn">[ USERS ]</label>

                <?php if (!$is_guest): ?>
                    <?php if ($rank >= 5): ?>
                        <label for="invite-modal-toggle" class="opt-btn opt-btn-green">[ INVITE ]</label>
                    <?php endif; ?>
                    <a href="#" class="opt-btn" style="color:#444; cursor:not-allowed;" title="Not Installed">[ UPLOAD ]</a>
                <?php endif; ?>
                
                <a href="chat_input.php" target="chat_input" class="opt-btn">[ REFRESH ]</a>

                <span style="margin-left:auto; color:#333;">SECURE_V2</span>
            </div>

            <div class="status-buffer"></div>
        </div>
    </div>

</body>
</html>