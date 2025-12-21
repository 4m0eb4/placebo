<?php
session_start();
require 'db_config.php';

// --- SECURITY: ADMIN CHECK (RANK 9+) ---
// Rank 9 = General Admin | Rank 10 = Owner (Config/Logs)
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['rank']) || (int)$_SESSION['rank'] < 9) {
    ?>
    <!DOCTYPE html>
    <html><head><title>Restricted</title><link rel="stylesheet" href="style.css"></head>
    <body style="background:#000;">
    <div class="login-wrapper">
        <div class="terminal-header"><span class="term-title">SYSTEM_ALERT</span></div>
        <div style="padding:40px; text-align:center;">
            <h1 style="color:#e06c75; font-size:1.5rem; margin-top:0;">ACCESS DENIED</h1>
            <p style="color:#666; font-family:monospace; margin-bottom:30px;">ADMIN CLEARANCE (RANK 9+) REQUIRED.<br>INCIDENT LOGGED.</p>
            <a href="index.php" class="btn-primary" style="display:inline-block; width:auto; text-decoration:none;">RETURN TO FEED</a>
        </div>
    </div>
    </body></html>
    <?php
    exit;
}

$tab = $_GET['view'] ?? 'config';
$msg = '';

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // System Broadcast
    if (isset($_POST['send_sys_msg'])) {
        $txt = strip_tags($_POST['sys_msg_text']);
        $style = $_POST['sys_msg_type']; 
        $emoji = match($style) {
            'WARNING' => '⚠️ [RED ALERT] ',
            'CRITICAL' => '☣️ [CRITICAL] ',
            'MAINT' => '🛠️ [MAINTENANCE] ',
            'SUCCESS' => '✅ [SUCCESS] ',
            default => 'ℹ️ [INFO] '
        };
        $body = "[glitch]" . $emoji . $txt . "[/glitch]";
        
        $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, msg_type) VALUES (0, 'SYSTEM', ?, 10, 'system')")
            ->execute([$body]);
        $msg = "System Alert Broadcasted.";
    }

    // 1. SAVE CONFIG (STRICT OWNER ONLY)
    if ($tab === 'config') {
        if ($_SESSION['rank'] < 10) {
            $msg = "ACCESS DENIED: LEVEL 10 REQUIRED FOR CONFIGURATION.";
        } else {
            // --- HANDLE BACKGROUND ---
            $bg_path = $_POST['saved_bg_url'] ?? ''; 
        
            if (isset($_POST['remove_bg'])) {
                $bg_path = ''; 
            }

            if (isset($_FILES['bg_upload']) && $_FILES['bg_upload']['error'] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['bg_upload']['tmp_name'];
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($tmp);
                } else {
                    $mime = $_FILES['bg_upload']['type'];
                }
                $allowed_mimes = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];

                if (array_key_exists($mime, $allowed_mimes)) {
                    $ext = $allowed_mimes[$mime];
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    
                    $new_filename = 'bg_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $target_file = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($tmp, $target_file)) {
                        $bg_path = "uploads/" . $new_filename;
                    } else {
                        $msg = "Error: File move failed.";
                    }
                } else {
                    $msg = "Error: Invalid Image Type ($mime).";
                }
            }

            $upd = [
                'captcha_grid_w' => $_POST['grid_w'],
                'captcha_grid_h' => $_POST['grid_h'],
                'captcha_cell_size' => $_POST['cell_size'],
                'captcha_min_sum' => $_POST['min_sum'],
                'captcha_max_sum' => $_POST['max_sum'],
                'captcha_active_min' => $_POST['active_min'],
                'captcha_active_max' => $_POST['active_max'],
                'pgp_message' => $_POST['pgp_msg'],
                'login_message' => $_POST['login_msg'],
                'chat_emoji_presets' => $_POST['emoji_presets'],
                'palette_json' => $_POST['palette'],
                'site_theme' => $_POST['site_theme'],
                'site_bg_url' => $bg_path,
                'max_chat_history' => $_POST['max_history'] ?? 150,
                'invite_min_rank' => $_POST['invite_min_rank'] ?? 5,
                'registration_enabled' => isset($_POST['reg_enabled']) ? '1' : '0',
                'registration_msg' => $_POST['reg_msg']
            ];

            foreach($upd as $k=>$v) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$k, $v, $v]);
            }
            $msg = $msg ?: "Configuration Saved.";
            
            // Log Action (Session + FP, NO IP)
            $fp_log = "FP:NONE";
            try {
                $stmt_fp = $pdo->prepare("SELECT pgp_fingerprint FROM users WHERE id = ?");
                $stmt_fp->execute([$_SESSION['user_id']]);
                $fp_full = $stmt_fp->fetchColumn();
                if ($fp_full) $fp_log = "FP:" . substr(str_replace(' ','',$fp_full), -4); 
            } catch (Exception $e) {}
            
            $log_ident = "SID:" . substr(session_id(), 0, 6) . ".." . " | " . $fp_log;

            $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
                ->execute([$_SESSION['user_id'], $_SESSION['username'], "Updated Settings", $log_ident]);
        }
    }
    
    // 2. USER ACTIONS
    if ($tab === 'users') {
        if (isset($_POST['delete_user'])) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND rank < 10");
            $stmt->execute([$_POST['user_id']]);
            $msg = "User Deleted.";
        }
        if (isset($_POST['update_rank'])) {
            $stmt = $pdo->prepare("UPDATE users SET rank = ? WHERE id = ? AND rank < 10");
            $stmt->execute([$_POST['new_rank'], $_POST['user_id']]);
            $msg = "Rank Updated.";
        }
        if (isset($_POST['save_ranks'])) {
            $new_ranks = $_POST['rank_names'] ?? [];
            $clean_ranks = [];
            foreach($new_ranks as $k => $v) {
                $k = (int)$k;
                if($k >= 1 && $k <= 9) $clean_ranks[$k] = strip_tags(trim($v));
            }
            $json = json_encode($clean_ranks);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('rank_config', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$json, $json]);
            $msg = "Rank Names Updated.";
        }
    }

    // 3. POST ACTIONS
    if ($tab === 'posts') {
        if (isset($_POST['delete_post'])) {
            $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $stmt->execute([$_POST['post_id']]);
            $msg = "Post Deleted.";
        }
    }

    // 4. LOG ACTIONS
    if ($tab === 'logs') {
        if (isset($_POST['delete_log'])) {
            $stmt = $pdo->prepare("DELETE FROM security_logs WHERE id = ?");
            $stmt->execute([$_POST['log_id']]);
            $msg = "Log Entry Pruned.";
        }
        if (isset($_POST['clear_logs'])) {
            $pdo->exec("TRUNCATE TABLE security_logs");
            $msg = "All Logs Purged.";
        }
    }

    // 5. CHAT ACTIONS
    if ($tab === 'chat') {
        if (isset($_POST['repair_chat_db'])) {
            $pdo->exec("DROP TABLE IF EXISTS chat_messages");
            $pdo->exec("CREATE TABLE chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                rank INT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (created_at)
            )");
            $msg = "Chat Database Repaired & Reset.";
        }
        if (isset($_POST['delete_msg'])) {
            $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE id = ?");
            $stmt->execute([$_POST['msg_id']]);
            $msg = "Message Deleted.";
        }
        if (isset($_POST['purge_chat'])) {
            $pdo->exec("TRUNCATE TABLE chat_messages");
            $pdo->exec("TRUNCATE TABLE chat_reactions"); 
            $pdo->exec("INSERT INTO chat_signals (signal_type) VALUES ('PURGE')");
            $msg = "Chat History Cleared & Signal Sent.";
        }
    }

    // 6. LINK ACTIONS
    if ($tab === 'links') {
        // MANUAL ADD
        if (isset($_POST['manual_add_link'])) {
            $url = $_POST['new_url'];
            $title = $_POST['new_title'];
            $pdo->prepare("INSERT INTO shared_links (url, title, posted_by, status) VALUES (?, ?, 'ADMIN', 'approved')")
                ->execute([$url, $title]);
            $msg = "Link Added manually.";
        }
        
        // EDIT EXISTING
        if (isset($_POST['update_link'])) {
            $pdo->prepare("UPDATE shared_links SET title = ? WHERE id = ?")
                ->execute([$_POST['edit_title'], $_POST['edit_id']]);
            $msg = "Link Updated.";
        }

        // DELETE
        if (isset($_POST['delete_link'])) {
            $pdo->prepare("DELETE FROM shared_links WHERE id = ?")->execute([$_POST['del_id']]);
            $msg = "Link Deleted.";
        }

        // APPROVE
        if (isset($_POST['approve_link'])) {
            $lid = $_POST['link_id'];
            $title_val = trim($_POST['link_title']);
            $app_msg = trim($_POST['approval_msg'] ?? ''); 
            
            // 1. Mark Approved
            $stmt = $pdo->prepare("UPDATE shared_links SET status = 'approved', title = ? WHERE id = ?");
            $stmt->execute([$title_val, $lid]);
            
            // 2. Fetch & Release
            $stmt_l = $pdo->prepare("SELECT original_message, posted_by FROM shared_links WHERE id = ?");
            $stmt_l->execute([$lid]);
            $link_row = $stmt_l->fetch();
            
            if ($link_row && !empty($link_row['original_message'])) {
                $u_stmt = $pdo->prepare("SELECT id, rank, chat_color FROM users WHERE username = ?");
                $u_stmt->execute([$link_row['posted_by']]);
                $u_row = $u_stmt->fetch();
                
                $final_msg = $link_row['original_message'];
                if (!empty($app_msg)) {
                    $final_msg .= "\n\n[quote=SYSTEM][color=#6a9c6a]APPROVED:[/color] " . htmlspecialchars($app_msg) . "[/quote]";
                }

                $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, color_hex, msg_type) VALUES (?, ?, ?, ?, ?, 'normal')")
                    ->execute([$u_row['id']??0, $link_row['posted_by'], $final_msg, $u_row['rank']??1, $u_row['chat_color']??'#888']);
            }
            $msg = "Link Approved.";
        }
        
        // BAN
        if (isset($_POST['ban_link'])) {
            $stmt = $pdo->prepare("UPDATE shared_links SET status = 'banned' WHERE id = ?");
            $stmt->execute([$_POST['link_id']]);
            
            $url_val = $_POST['url_val'] ?? '';
            $parsed = parse_url($url_val);
            $domain = $parsed['host'] ?? $url_val;
            
            if ($domain) {
                $pdo->prepare("INSERT INTO banned_patterns (pattern, reason) VALUES (?, 'Malicious Link')")->execute([$domain]);
                $msg = "Link Banned & Domain '$domain' added to Blacklist.";
            } else {
                $msg = "Link Banned.";
            }
        }
    }

    // 7. AUTOMOD ACTIONS
    if ($tab === 'automod') {
        // A. Username Blacklist
        if (isset($_POST['save_name_blacklist'])) {
            $list = trim($_POST['name_blacklist']);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('blacklist_usernames', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$list, $list]);
            $msg = "Username Restrictions Updated.";
        }

        // B. Content Patterns
        if (isset($_POST['add_pattern'])) {
            $pat = trim($_POST['pattern']);
            if ($pat) {
                $pdo->prepare("INSERT INTO banned_patterns (pattern, reason) VALUES (?, ?)")
                    ->execute([$pat, $_POST['reason'] ?? 'Manual Ban']);
                $msg = "Pattern Added.";
            }
        }
        if (isset($_POST['delete_pattern'])) {
            $pdo->prepare("DELETE FROM banned_patterns WHERE id = ?")->execute([$_POST['pattern_id']]);
            $msg = "Pattern Removed.";
        }
    }
}
// --- DATA FETCHING ---
$settings = [];
$stmt = $pdo->query("SELECT * FROM settings");
while($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];


$rank_names = json_decode($settings['rank_config'] ?? '', true) ?? [1 => 'User', 5 => 'VIP', 9 => 'Admin'];

$users = [];
if ($tab === 'users') {
    $users = $pdo->query("SELECT * FROM users ORDER BY rank DESC, id ASC")->fetchAll();
}
$posts = [];
if ($tab === 'posts') {
    $posts = $pdo->query("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id ORDER BY created_at DESC")->fetchAll();
}
$logs = [];
if ($tab === 'logs') {
    $logs = $pdo->query("SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Control</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body.admin-mode { display: block !important; margin: 0 !important; background: #0d0d0d; } /* Ensure body bg matches panel */
        .admin-layout { display: grid; grid-template-columns: 200px 1fr; min-height: 100vh; }
        /* Ensure sidebar tracks full height */
        .sidebar { background: #161616; border-right: 1px solid #333; padding-top: 10px; height: 100%; min-height: 100vh; box-sizing: border-box; }
        .sidebar a { display: block; padding: 12px 15px; color: #888; border-bottom: 1px solid #222; font-size: 0.7rem; letter-spacing: 1px; text-decoration: none; }
        .sidebar a:hover, .sidebar a.active { background: #1f1f1f; color: #fff; border-left: 3px solid #6a9c6a; }
        .main-panel { padding: 30px; background: #0d0d0d; min-width: 0; /* Prevents grid blowout */ }
        .panel-header { margin-bottom: 25px; border-bottom: 1px solid #333; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-end; }
        .panel-title { font-size: 1.1rem; color: #d19a66; margin: 0; }
        
        /* IMPROVED TABLE LAYOUT */
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; table-layout: fixed; }
        .data-table th { text-align: left; padding: 8px; border-bottom: 1px solid #444; color: #6a9c6a; font-size: 0.7rem; }
        .data-table td { 
            padding: 8px; border-bottom: 1px solid #222; color: #ccc; 
            word-wrap: break-word; overflow-wrap: anywhere; /* Aggressive wrapping for hashes/keys */
            vertical-align: top;
        }
        .data-table tr:hover { background: #111; }
        
        /* FORM FIXES */
        input[type="number"], input[type="text"], textarea, select { 
            background: #080808 !important; border: 1px solid #333 !important; color: #fff !important; 
            padding: 8px; font-family: monospace; width: 100%; box-sizing: border-box;
        }
        .badge { padding: 2px 5px; border-radius: 2px; font-size: 0.65rem; background: #333; border: 1px solid #444; }
        .badge-10 { border-color: #d19a66; color: #d19a66; background: transparent; } 
    </style>
</head>
<body class="admin-mode <?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>

<div class="admin-layout">
    <div class="sidebar">
        <div style="padding: 0 20px 20px 20px; color: #fff; font-weight: bold;">ADMIN_V2</div>
        <a href="?view=config" class="<?= $tab=='config'?'active':'' ?>">GENERAL CONFIG</a>
        <a href="?view=users" class="<?= $tab=='users'?'active':'' ?>">USER MANAGEMENT</a>
        <a href="?view=posts" class="<?= $tab=='posts'?'active':'' ?>">POST MANAGEMENT</a>
        <a href="?view=logs" class="<?= $tab=='logs'?'active':'' ?>">SECURITY LOGS</a>
        <a href="?view=chat" class="<?= $tab=='chat'?'active':'' ?>">CHAT CONTROL</a>
        <a href="?view=links" class="<?= $tab=='links'?'active':'' ?>">LINK MANAGEMENT</a>
        <a href="?view=automod" class="<?= $tab=='automod'?'active':'' ?>">AUTOMOD</a>
        <a href="index.php" style="margin-top: 50px; border-top: 1px solid #333;">&lt; RETURN TO SITE</a>
    </div>

    <div class="main-panel">
        
<?php if($tab === 'config'): ?>
        <div class="panel-header"><h2 class="panel-title">System Configuration</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" style="max-width: 600px;">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #333; padding-bottom: 5px;">
                <h3 style="color:#6a9c6a; font-size:0.9rem; margin: 0;">ACCESS CONTROL</h3>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size: 0.7rem;">
                    <input type="checkbox" name="reg_enabled" value="1" <?= ($settings['registration_enabled']??'1')=='1' ? 'checked' : '' ?>>
                    <span style="color:#ccc;">ALLOW REGISTRATIONS</span>
                </label>
            </div>
            <div class="input-group">
                <label>REGISTRATION CLOSED MESSAGE</label>
                <textarea name="reg_msg" style="height: 80px;"><?= htmlspecialchars($settings['registration_msg'] ?? "Registration Closed.") ?></textarea>
            </div>

            <h3 style="color:#6a9c6a; font-size:0.9rem; margin-bottom:15px;">CAPTCHA PARAMETERS</h3>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                <div class="input-group"><label>Grid Width</label><input type="number" name="grid_w" value="<?= $settings['captcha_grid_w']?>"></div>
                <div class="input-group"><label>Grid Height</label><input type="number" name="grid_h" value="<?= $settings['captcha_grid_h']?>"></div>
                <div class="input-group"><label>Cell Size (px)</label><input type="number" name="cell_size" value="<?= $settings['captcha_cell_size']?>"></div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                <div class="input-group"><label>Active Colors (Min)</label><input type="number" name="active_min" value="<?= $settings['captcha_active_min'] ?? 3 ?>"></div>
                <div class="input-group"><label>Active Colors (Max)</label><input type="number" name="active_max" value="<?= $settings['captcha_active_max'] ?? 5 ?>"></div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                <div class="input-group"><label>Target Min</label><input type="number" name="min_sum" value="<?= $settings['captcha_min_sum']?>"></div>
                <div class="input-group"><label>Target Max</label><input type="number" name="max_sum" value="<?= $settings['captcha_max_sum']?>"></div>
            </div>

            <h3 style="color:#6a9c6a; font-size:0.9rem; margin: 20px 0 15px 0;">SITE CONTROLS</h3>
            <div class="input-group">
                <label>MAX CHAT HISTORY (Pruning Limit)</label>
                <input type="number" name="max_history" value="<?= $settings['max_chat_history'] ?? 150 ?>" min="10" style="width:100%; padding:10px;">
            </div>
            <div class="input-group">
                <label>PGP Challenge Message</label>
                <textarea name="pgp_msg" style="height: 60px;"><?= htmlspecialchars($settings['pgp_message'] ?? '') ?></textarea>
            </div>
            <div class="input-group">
                <label>Login Welcome Message (BBCode)</label>
                <textarea name="login_msg" style="height: 60px;"><?= htmlspecialchars($settings['login_message'] ?? '') ?></textarea>
            </div>
            <div class="input-group">
                <label>Chat Emoji Presets</label>
                <input type="text" name="emoji_presets" value="<?= htmlspecialchars($settings['chat_emoji_presets'] ?? '❤️,🔥,👍,💀') ?>" style="width:100%; padding:10px;">
            </div>
            <div class="input-group">
                <label>Color Palette (JSON)</label>
                <textarea name="palette" style="height: 100px; font-family: monospace;"><?= htmlspecialchars($settings['palette_json']) ?></textarea>
            </div>

            <h3 style="color:#6a9c6a; font-size:0.9rem; margin: 20px 0 15px 0;">GLOBAL VISUAL THEME</h3>
            
            <div class="input-group" style="border: 1px dashed #333; padding: 10px; margin-bottom: 15px;">
                <label style="color: #e5c07b;">INVITE SYSTEM RESTRICTION</label>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.7rem; color: #666;">Min Rank:</span>
                    <input type="number" name="invite_min_rank" value="<?= $settings['invite_min_rank'] ?? 5 ?>" min="1" max="10" style="width: 60px; text-align: center;">
                </div>
            </div>

            <div class="input-group">
                <label>Theme Selection</label>
                <select name="site_theme" style="width:100%; background:#080808; border:1px solid #333; color:#ccc; padding:10px;">
                    <option value="" <?= ($settings['site_theme']??'')==''?'selected':'' ?>>Default (System)</option>
                    <option value="theme-christmas" <?= ($settings['site_theme']??'')=='theme-christmas'?'selected':'' ?>>Christmas</option>
                    <option value="theme-spooky" <?= ($settings['site_theme']??'')=='theme-spooky'?'selected':'' ?>>Halloween / Spooky</option>
                    <option value="theme-matrix" <?= ($settings['site_theme']??'')=='theme-matrix'?'selected':'' ?>>Matrix / Terminal</option>
                </select>
            </div>

            <div class="input-group">
                <label>Background Image</label>
                <input type="hidden" name="saved_bg_url" value="<?= htmlspecialchars($settings['site_bg_url']??'') ?>">
                <input type="file" name="bg_upload" style="background:#080808; border:1px solid #333; padding:10px; width:100%;">
                <?php if(!empty($settings['site_bg_url'])): ?>
                    <div style="margin-top:5px; padding:5px; border:1px solid #333; background:#111;">
                        <label style="color:#e06c75; font-size:0.7rem; cursor:pointer;"><input type="checkbox" name="remove_bg"> REMOVE BACKGROUND</label>
                        <div style="font-size:0.7rem; color:#666;"><?= htmlspecialchars($settings['site_bg_url']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 20px;">APPLY CHANGES</button>
        </form>
        <?php endif; ?>

        <?php if($tab === 'users'): ?>
        <div class="panel-header"><h2 class="panel-title">User Registry</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

        <details style="margin-bottom: 20px; border: 1px solid #333; background: #111;">
            <summary style="padding: 15px; cursor: pointer; color: #e5c07b; font-size: 0.8rem; font-weight: bold; outline: none;">RANK DEFINITIONS [+]</summary>
            <div style="padding: 15px; border-top: 1px solid #333;">
                <form method="POST" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px;">
                    <div class="input-group" style="margin:0;"><label>Rank 10</label><input type="text" value="OWNER" disabled></div>
                    <?php for($r=9; $r>=1; $r--): ?>
                    <div class="input-group" style="margin:0;"><label>Rank <?= $r ?></label><input type="text" name="rank_names[<?= $r ?>]" value="<?= htmlspecialchars($rank_names[$r] ?? "Rank $r") ?>"></div>
                    <?php endfor; ?>
                    <div style="grid-column: 1 / -1; margin-top:10px;"><button type="submit" name="save_ranks" class="badge" style="width:100%; padding:10px; cursor:pointer;">UPDATE NAMES</button></div>
                </form>
            </div>
        </details>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>Username</th>
                    <th style="width: 60px;">Rank</th>
                    <th>Created</th>
                    <th style="width: 160px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($users as $u): ?>
            <tr>
                <td>#<?= $u['id'] ?></td>
                <td><a href="profile.php?id=<?= $u['id'] ?>" style="color: #e0e0e0;"><?= htmlspecialchars($u['username']) ?></a></td>
                <td><span class="badge badge-<?= $u['rank'] ?>"><?= $u['rank'] ?></span></td>
                <td><?= $u['created_at'] ?></td>
                <td style="white-space: nowrap;">
                    <?php if($u['rank'] < 10): ?>
                    <form method="POST" style="display:flex; align-items:center; gap:4px; margin:0;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="number" name="new_rank" value="<?= $u['rank'] ?>" style="width:40px; padding:2px; color:#fff; text-align:center; height:20px; font-size:0.7rem;">
                        <button type="submit" name="update_rank" class="badge" style="cursor:pointer; border:none; padding:3px 6px;">OK</button>
                        <span style="color:#444;">|</span>
                        <label style="font-size:0.6rem; color:#e06c75; display:flex; align-items:center; gap:2px;"><input type="checkbox" name="confirm_del" required> ?</label>
                        <button type="submit" name="delete_user" class="badge" style="cursor:pointer; border:none; background:#e06c75; padding:3px 6px;">X</button>
                    </form>
                    <?php else: ?><span style="color:#555; font-size:0.6rem;">[ PROTECTED ]</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if($tab === 'posts'): ?>
        <div class="panel-header">
            <h2 class="panel-title">Transmissions</h2>
            <a href="create_post.php" class="btn-primary" style="width:auto; padding: 8px 15px; font-size:0.7rem;">+ NEW</a>
        </div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>
        <table class="data-table">
            <thead><tr><th>ID</th><th>Title</th><th>Author</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach($posts as $p): ?>
            <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['title']) ?></td>
                <td><?= htmlspecialchars($p['username']) ?></td>
                <td><?= $p['created_at'] ?></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                        <a href="create_post.php?edit=<?= $p['id'] ?>" class="badge" style="color:#fff; text-decoration:none;">EDIT</a>
                        <button type="submit" name="delete_post" class="badge" style="cursor:pointer; border:none; background:#e06c75;" onclick="return confirm('Delete?');">DEL</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
<?php if($tab === 'logs'): ?>
        <div class="panel-header">
            <h2 class="panel-title">Security Logs</h2>
            <form method="POST" onsubmit="return confirm('WARNING: Wipe ALL logs?');">
                <button type="submit" name="clear_logs" class="badge" style="background:#e06c75; border:none; cursor:pointer;">PURGE ALL</button>
            </form>
        </div>
        <table class="data-table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>SESSION / FP</th><th>Opt</th></tr></thead>
            <tbody>
            <?php foreach($logs as $l): ?>
            <tr>
                <td style="color:#666;"><?= $l['created_at'] ?></td>
                <td style="color:#d19a66;"><?= htmlspecialchars($l['username']) ?></td>
                <td><?= htmlspecialchars($l['action']) ?></td>
                <td style="font-family:monospace; font-size: 0.7rem; color: #888;"><?= htmlspecialchars($l['ip_addr']) ?></td>
                <td>
                    <form method="POST"><input type="hidden" name="log_id" value="<?= $l['id'] ?>"><button type="submit" name="delete_log" class="badge" style="background:none; border:none; color:#555; cursor:pointer;">x</button></form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>


        <?php if($tab === 'chat'): ?>
        <div class="panel-header"><h2 class="panel-title">Chat System Controls</h2></div>
        <div style="padding: 20px; background: #111; border: 1px solid #333; text-align: center;">
            <div style="display:flex; justify-content: center; gap:20px;">
                <form method="POST" onsubmit="return confirm('Fix Database? Wipes chat history.');"><button type="submit" name="repair_chat_db" class="btn-primary" style="width: auto; background:#6a9c6a; border-color:#6a9c6a; color:#000;">REPAIR DATABASE</button></form>
                <form method="POST" onsubmit="return confirm('Wipe ALL chat messages?');"><button type="submit" name="purge_chat" class="btn-primary" style="width: auto; background:#e06c75; border-color:#e06c75;">PURGE MESSAGES</button></form>
            </div>
        </div>
        <div style="margin-top:20px; padding: 20px; background: #111; border: 1px solid #333;">
            <h3 style="margin-top:0; color:#ccc; font-size:0.9rem;">SYSTEM BROADCAST</h3>
            <form method="POST" style="display:flex; gap:10px;">
                <select name="sys_msg_type" style="background:#000; color:#fff; border:1px solid #444; padding:10px;">
                    <option value="WARNING">RED ALERT</option>
                    <option value="INFO">BLUE INFO</option>
                    <option value="SUCCESS">GREEN SUCCESS</option>
                    <option value="CRITICAL">PURPLE CRITICAL</option>
                    <option value="MAINT">ORANGE MAINT</option>
                </select>
                <input type="text" name="sys_msg_text" placeholder="Message content..." required style="flex-grow:1; background:#000; color:#fff; border:1px solid #444; padding:10px;">
                <button type="submit" name="send_sys_msg" class="btn-primary" style="width:auto; padding:0 20px;">BROADCAST</button>
            </form>
        </div>
        <?php endif; ?>

<?php if($tab === 'links'): ?>
    <?php 
        $cats = [];
        try {
            $cats = $pdo->query("SELECT * FROM link_categories ORDER BY display_order ASC")->fetchAll(); 
        } catch(Exception $e) {
            echo "<div class='error'>Warning: Link Categories table missing. Run 'db_fix_links.php'</div>";
        }
    ?>
    
    <div style="margin-bottom:30px; border:1px solid #333; padding:15px; background:#111;">
        <h3 style="margin-top:0; color:#6a9c6a; font-size:0.9rem;">+ MANUAL LINK INJECTION</h3>
        <form method="POST" style="display:grid; grid-template-columns: 2fr 2fr 1fr 1fr; gap:10px;">
            <input type="text" name="new_url" placeholder="https://..." required style="background:#000; color:#fff; border:1px solid #333; padding:8px;">
            <input type="text" name="new_title" placeholder="Link Title..." required style="background:#000; color:#fff; border:1px solid #333; padding:8px;">
            
            <select name="cat_id" style="background:#000; color:#fff; border:1px solid #333; padding:8px;">
                <?php foreach($cats as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="manual_add_link" class="btn-primary" style="width:auto;">ADD LINK</button>
        </form>
    </div>

        <div class="panel-header"><h2 class="panel-title">Pending Intercepts</h2></div>
        <table class="data-table" style="margin-bottom:30px;">
            <thead><tr><th>Details</th><th>Action</th></tr></thead>
            <tbody>
            <?php 
            $pending = $pdo->query("SELECT * FROM shared_links WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
            if(empty($pending)) echo "<tr><td colspan='2' style='text-align:center; color:#444;'>No pending links.</td></tr>";
            foreach($pending as $l): ?>
            <tr>
                <td style="max-width:500px; overflow:hidden; white-space:nowrap; vertical-align:middle;">
                    <span style="color:#666; font-size:0.7rem;">[<?= htmlspecialchars($l['posted_by']) ?>]</span>
                    <a href="<?= htmlspecialchars($l['url']) ?>" target="_blank" style="color:#e5c07b; margin-left:10px; text-decoration:none; font-family:monospace;"><?= htmlspecialchars(substr($l['url'], 0, 60)) ?>...</a>
                </td>
                <td style="vertical-align:middle;">
                    <form method="POST" style="display:flex; gap:5px; align-items:center; margin:0;">
                        <input type="hidden" name="link_id" value="<?= $l['id'] ?>">
                        <input type="hidden" name="url_val" value="<?= htmlspecialchars($l['url']) ?>">
                        
                        <input type="text" name="link_title" placeholder="Link Title..." style="width:120px; padding:5px; font-size:0.7rem; background:#000; color:#fff; border:1px solid #333;">
                        <input type="text" name="approval_msg" placeholder="Approve Msg..." style="width:120px; padding:5px; font-size:0.7rem; background:#000; color:#aaa; border:1px solid #333;">
                        
                        <button type="submit" name="approve_link" class="badge" style="background:#6a9c6a; color:#000; border:none; cursor:pointer; padding:6px 10px; font-weight:bold;">OK</button>
                        <button type="submit" name="ban_link" class="badge" style="background:#e06c75; color:#000; border:none; cursor:pointer; padding:6px 10px; font-weight:bold;">BAN</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="panel-header"><h2 class="panel-title">Active Database</h2></div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title / URL</th>
                    <th style="width: 90px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            $approved = $pdo->query("SELECT * FROM shared_links WHERE status = 'approved' ORDER BY created_at DESC LIMIT 50")->fetchAll();
            foreach($approved as $l): ?>
            <tr>
                <td>
                    <form method="POST" style="display:flex; gap:10px;">
                        <input type="hidden" name="edit_id" value="<?= $l['id'] ?>">
                        <div style="flex:1;">
                            <input type="text" name="edit_title" value="<?= htmlspecialchars($l['title'] ?? '') ?>" placeholder="No Title" style="width:100%; background:transparent; border:none; color:#d19a66; font-weight:bold;">
                            <div style="font-size:0.7rem; color:#444;"><?= htmlspecialchars($l['url']) ?></div>
                        </div>
                        <button type="submit" name="update_link" class="badge" style="background:#333; color:#aaa; border:none; cursor:pointer;">SAVE</button>
                    </form>
                </td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="del_id" value="<?= $l['id'] ?>">
                        <button type="submit" name="delete_link" class="badge" style="background:#e06c75; border:none; cursor:pointer; color:#000;">DELETE</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

<?php endif; ?>
        <?php if($tab === 'automod'): ?>
        <div class="panel-header"><h2 class="panel-title">Automod & Restrictions</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

        <div style="background:#111; padding:15px; margin-bottom:30px; border:1px solid #333;">
            <h3 style="color:#d19a66; font-size:0.9rem; margin-top:0;">RESTRICTED USERNAMES</h3>
            <form method="POST">
                <div style="margin-bottom:10px; font-size:0.7rem; color:#666;">Comma-separated list of banned names or substrings (e.g. admin, mod, staff).</div>
                <textarea name="name_blacklist" style="width:100%; height:80px; background:#000; border:1px solid #444; color:#fff; padding:10px; font-family:monospace;"><?= htmlspecialchars($settings['blacklist_usernames'] ?? 'admin,root,system,mod,support,placebo') ?></textarea>
                <button type="submit" name="save_name_blacklist" class="btn-primary" style="margin-top:10px; width:auto; padding:8px 15px;">SAVE RESTRICTIONS</button>
            </form>
        </div>

        <h3 style="color:#e06c75; font-size:0.9rem; margin-bottom:10px;">BANNED CONTENT PATTERNS (URLS/TEXT)</h3>
        <div style="background:#111; padding:15px; margin-bottom:20px; border:1px solid #333;">
            <form method="POST" style="display:flex; gap:10px;">
                <input type="text" name="pattern" placeholder="String or Domain to ban..." required style="flex:1; background:#000; border:1px solid #444; color:#fff; padding:8px;">
                <input type="text" name="reason" placeholder="Reason (Optional)" style="width:150px; background:#000; border:1px solid #444; color:#fff; padding:8px;">
                <button type="submit" name="add_pattern" class="btn-primary" style="width:auto; padding:0 15px;">ADD RULE</button>
            </form>
        </div>

        <table class="data-table">
            <thead><tr><th>Pattern</th><th>Reason</th><th>Action</th></tr></thead>
            <tbody>
            <?php 
            $patterns = $pdo->query("SELECT * FROM banned_patterns ORDER BY id DESC")->fetchAll();
            foreach($patterns as $p): ?>
            <tr>
                <td style="color:#e06c75; font-family:monospace;"><?= htmlspecialchars($p['pattern']) ?></td>
                <td style="color:#666;"><?= htmlspecialchars($p['reason']) ?></td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="pattern_id" value="<?= $p['id'] ?>">
                        <button type="submit" name="delete_pattern" class="badge" style="background:none; border:none; color:#555; cursor:pointer;">[x]</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    </div>
</div>
</body>
</html>