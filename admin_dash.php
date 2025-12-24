<?php
session_start();
require 'db_config.php';
require_once 'bbcode.php'; // Required for username previews
// --- SECURITY: DYNAMIC PERMISSION CHECK ---
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['rank'])) { header("Location: login.php"); exit; }

// 1. Fetch Perms
$p_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$raw = $p_stmt->fetchColumn();
$sys_perms = json_decode($raw, true) ?? [];

// 2. Define Requirements (Defaults if not set)
$req_logs  = $sys_perms['perm_view_logs'] ?? 10;
$req_users = $sys_perms['perm_manage_users'] ?? 9;
$req_chat  = $sys_perms['perm_chat_config'] ?? 9;
$req_automod = $sys_perms['perm_user_ban'] ?? 9; // Automod usually tied to Ban power

// 3. Current User Rank
$my_rank = (int)$_SESSION['rank'];

// 4. Global Gatekeeper (Minimum Rank to see Dash at all)
// We set this to the LOWEST of the requirements, so if you set Chat Config to Rank 5, they can get in.
$min_entry = min($req_logs, $req_users, $req_chat, $req_automod, 10); 

if ($my_rank < $min_entry) {
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

// --- TAB SECURITY GUARD ---
// If user tries to access ?view=logs but their rank is too low, kick them to default.
if ($tab === 'logs' && $my_rank < $req_logs) $tab = 'error';
if ($tab === 'users' && $my_rank < $req_users) $tab = 'error';
if ($tab === 'chat' && $my_rank < $req_chat) $tab = 'error';
if ($tab === 'automod' && $my_rank < $req_automod) $tab = 'error';
if ($tab === 'config' && $my_rank < 10) $tab = 'error'; // Config always 10

if ($tab === 'error') {
    echo "<div style='color:#e06c75; background:#1a0505; padding:20px; text-align:center; font-family:monospace;'>ACCESS DENIED: INSUFFICIENT CLEARANCE FOR THIS MODULE.</div>";
    exit;
}

$msg = '';

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // System Broadcast
    if (isset($_POST['send_sys_msg'])) {
        $txt = strip_tags($_POST['sys_msg_text']);
        $style = $_POST['sys_msg_type']; 
        $custom_hex = trim($_POST['sys_custom_hex'] ?? '');
        $custom_label = trim($_POST['sys_custom_label'] ?? '');

        // Validation: Ensure hex starts with # if provided
        if (!empty($custom_hex) && $custom_hex[0] !== '#') $custom_hex = '#' . $custom_hex;

        if (!empty($custom_hex) || !empty($custom_label) || $style === 'BLANK') {
            $final_hex = !empty($custom_hex) ? $custom_hex : '#333333';
            // Explicitly set to empty string if style is BLANK or label is empty
            $final_label = !empty($custom_label) ? $custom_label : '';
            
            $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, color_hex, msg_type) VALUES (0, ?, ?, 10, ?, 'broadcast')")
                ->execute([$final_label, $txt, $final_hex]);
        } else {
            $emoji = match($style) {
                'WARNING' => '⚠️ [RED ALERT] ',
                'CRITICAL' => '☣️ [CRITICAL] ',
                'MAINT' => '🛠️ [MAINTENANCE] ',
                'SUCCESS' => '✅ [SUCCESS] ',
                default => 'ℹ️ [INFO] '
            };
            $body = $emoji . $txt;
            
            $pdo->prepare("INSERT INTO chat_messages (user_id, username, message, rank, msg_type) VALUES (0, 'SYSTEM', ?, 10, 'system')")
                ->execute([$body]);
        }
        $msg = "System Alert Broadcasted.";
    }

// 1. SAVE CONFIG (STRICT OWNER ONLY)
    if ($tab === 'config') {
        if ($_SESSION['rank'] < 10) {
            $msg = "ACCESS DENIED: LEVEL 10 REQUIRED FOR CONFIGURATION.";
        } else {
            // --- PALETTE MANAGER LOGIC ---
            
            // A. Handle DELETE (Immediate Action)
            if (isset($_POST['del_palette_key'])) {
                $raw_p = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='palette_json'")->fetchColumn();
                $curr_p = json_decode($raw_p ?: '[]', true);
                $del_k = $_POST['del_palette_key'];
                
                if (isset($curr_p[$del_k])) {
                    unset($curr_p[$del_k]);
                    $json_p = json_encode($curr_p);
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('palette_json', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                        ->execute([$json_p, $json_p]);
                    $msg = "Color '$del_k' Deleted.";
                    // Update POST to reflect change immediately if we fall through
                    $_POST['palette'] = $json_p; 
                }
            }
            // B. Handle SAVE/EDIT/ADD (Reconstruct JSON from Inputs)
            // If we are NOT deleting, and we see palette inputs, we rebuild the list.
            elseif (isset($_POST['pal_names']) || isset($_POST['new_pal_name'])) {
                $new_collection = [];
                
                // 1. Process Existing Rows (Edits)
                if (isset($_POST['pal_names']) && isset($_POST['pal_hexs'])) {
                    foreach ($_POST['pal_names'] as $idx => $name) {
                        $name = trim(strip_tags($name));
                        $hex = $_POST['pal_hexs'][$idx] ?? '#000000';
                        if ($name && preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                             list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
                             $new_collection[$name] = [$r, $g, $b];
                        }
                    }
                }
                
                // 2. Process "New Color" Input (Add)
                $new_n = trim(strip_tags($_POST['new_pal_name'] ?? ''));
                $new_h = $_POST['new_pal_hex'] ?? '';
                if ($new_n && preg_match('/^#[0-9a-fA-F]{6}$/', $new_h)) {
                    list($r, $g, $b) = sscanf($new_h, "#%02x%02x%02x");
                    $new_collection[$new_n] = [$r, $g, $b];
                }
                
                // Inject into POST so the main saver below uses this new JSON
                if (!empty($new_collection)) {
                    $_POST['palette'] = json_encode($new_collection);
                }
            }

            // --- HANDLE BACKGROUND ---

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
                'show_online_nodes' => isset($_POST['show_nodes']) ? '1' : '0',
                // [MOVED] Rank settings moved to Perms tab
                'registration_enabled' => isset($_POST['reg_enabled']) ? '1' : '0',
                'registration_msg' => $_POST['reg_msg'],
                'allow_op_mod' => isset($_POST['allow_op_mod']) ? '1' : '0'
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
        // --- PENALTY REMOVALS ---
        if (isset($_POST['quick_unban'])) {
            $pdo->prepare("UPDATE users SET is_banned = 0 WHERE id = ?")->execute([$_POST['user_id']]);
            $msg = "User Unbanned.";
        }
        if (isset($_POST['quick_unmute'])) {
            $pdo->prepare("UPDATE users SET is_muted = 0 WHERE id = ?")->execute([$_POST['user_id']]);
            $msg = "User Unmuted.";
        }
        if (isset($_POST['quick_unslow'])) {
            $pdo->prepare("UPDATE users SET slow_mode_override = 0 WHERE id = ?")->execute([$_POST['user_id']]);
            $msg = "Slow Mode Removed for User.";
        }

        // --- STANDARD ACTIONS ---
        if (isset($_POST['delete_user'])) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND rank < 10");
            $stmt->execute([$_POST['user_id']]);
            $msg = "User Deleted.";
        }
        if (isset($_POST['update_rank'])) {
            // SECURITY: strip_tags removes HTML (< >) but allows BBCode ([ ]) and CSS (; :)
            $c = strip_tags($_POST['chat_color'] ?? '');
            
            // Allow Rank 10 to edit anyone, including themselves
            if ($_SESSION['rank'] >= 10) {
                $stmt = $pdo->prepare("UPDATE users SET rank = ?, chat_color = ? WHERE id = ?");
                $stmt->execute([$_POST['new_rank'], $c, $_POST['user_id']]);
                $msg = "User Profile Updated.";
            } else {
                 // Lower admins cannot touch Rank 10s
                 $stmt = $pdo->prepare("UPDATE users SET rank = ?, chat_color = ? WHERE id = ? AND rank < 10");
                 $stmt->execute([$_POST['new_rank'], $c, $_POST['user_id']]);
                 $msg = "User Profile Updated (Restricted).";
            }
        }
        // --- ADDED BAN LOGIC ---
        if (isset($_POST['ban_user'])) {
            $pdo->prepare("UPDATE users SET is_banned = 1, force_logout = 1 WHERE id = ? AND rank < 10")->execute([$_POST['user_id']]);
            $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
                ->execute([$_SESSION['user_id'], $_SESSION['username'], "BANNED User #".$_POST['user_id'], "MANUAL_DASH"]);
            $msg = "User Banned & Kicked.";
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
        // A. GLOBAL CONFIG
        if (isset($_POST['save_chat_config'])) {
            $upd = [
                'chat_locked' => isset($_POST['chat_locked']) ? '1' : '0',
                'chat_lock_req' => (int)$_POST['chat_lock_req'], // New Setting
                'chat_slow_mode' => (int)$_POST['chat_slow_mode'],
                'chat_pinned_msg' => trim($_POST['chat_pinned_msg']),
                'chat_pin_style' => $_POST['chat_pin_style'] ?? 'INFO',
                'chat_pin_custom_color' => $_POST['chat_pin_custom_color'] ?? '#6a9c6a',
                'chat_pin_custom_emoji' => $_POST['chat_pin_custom_emoji'] ?? ''
            ];
            foreach($upd as $k=>$v) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$k, $v, $v]);
            }
            // Signal clients to refresh settings/pin
            $pdo->prepare("INSERT INTO chat_signals (signal_type) VALUES ('CONFIG_UPDATE')")->execute();
            $msg = "Chat Configuration Updated.";
        }

        // B. WIPE USER
        if (isset($_POST['wipe_user_chat'])) {
            $target = $_POST['wipe_username'];
            // Get ID first
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$target]);
            if ($uid = $stmt->fetchColumn()) {
                $pdo->prepare("DELETE FROM chat_messages WHERE user_id = ?")->execute([$uid]);
                $pdo->prepare("INSERT INTO chat_signals (signal_type) VALUES ('PURGE')")->execute();
                $msg = "All messages from '$target' obliterated.";
            } else {
                $msg = "User not found.";
            }
        }

        if (isset($_POST['repair_chat_db'])) {
            // 1. Re-create Chat Table with CORRECT columns for V10 logic
            $pdo->exec("DROP TABLE IF EXISTS chat_messages");
            $pdo->exec("CREATE TABLE chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                rank INT DEFAULT 1,
                color_hex VARCHAR(500) DEFAULT '#888',
                msg_type VARCHAR(20) DEFAULT 'normal',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (created_at)
            )");
            
            // 2. Patch Users Table (Expand color column for BBCode Animations)
            try {
                $pdo->exec("ALTER TABLE users MODIFY chat_color VARCHAR(500) DEFAULT '#888'");
            } catch (Exception $e) { /* Ignore if already exists/fails */ }

            $msg = "Database Structure Repaired (Chat Wiped + Columns Expanded).";
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
        // CATEGORY MANAGEMENT
        if (isset($_POST['add_cat'])) {
            $pdo->prepare("INSERT INTO link_categories (name, display_order) VALUES (?, 0)")->execute([$_POST['cat_name']]);
            $msg = "Category Added.";
        }
        if (isset($_POST['del_cat'])) {
            $pdo->prepare("DELETE FROM link_categories WHERE id = ?")->execute([$_POST['cat_id']]);
            $pdo->prepare("UPDATE shared_links SET category_id = NULL WHERE category_id = ?")->execute([$_POST['cat_id']]);
            $msg = "Category Deleted.";
        }

        // APPROVE ALL
        if (isset($_POST['approve_all'])) {
            $pdo->exec("UPDATE shared_links SET status = 'approved' WHERE status = 'pending'");
            $msg = "All Pending Links Approved.";
        }

        // MANUAL ADD
        if (isset($_POST['manual_add_link'])) {
            $url = $_POST['new_url'];
            $title = $_POST['new_title'];
            $cat_id = !empty($_POST['cat_id']) ? $_POST['cat_id'] : NULL;
            $pdo->prepare("INSERT INTO shared_links (url, title, posted_by, status, category_id) VALUES (?, ?, 'ADMIN', 'approved', ?)")
                ->execute([$url, $title, $cat_id]);
            $msg = "Link Added manually.";
        }
        
// EDIT EXISTING (SINGLE OR BULK)
        if (isset($_POST['update_link']) || isset($_POST['save_all_links'])) {
            // If specific button clicked, just do that one. If 'Save All', do loop.
            $targets = isset($_POST['update_link']) ? [$_POST['update_link']] : array_keys($_POST['links'] ?? []);
            
            foreach ($targets as $id) {
                if (isset($_POST['links'][$id])) {
                    $data = $_POST['links'][$id];
                    $cat_id = !empty($data['cat']) ? $data['cat'] : NULL;
                    $pdo->prepare("UPDATE shared_links SET title = ?, category_id = ? WHERE id = ?")
                        ->execute([$data['title'], $cat_id, $id]);
                }
            }
            $msg = (count($targets) > 1) ? "All Visible Links Updated." : "Link Updated.";
        }

        // DELETE (SINGLE)
        if (isset($_POST['delete_link'])) {
            $pdo->prepare("DELETE FROM shared_links WHERE id = ?")->execute([$_POST['delete_link']]);
            $msg = "Link Deleted.";
        }

        // BULK DELETE
        if (isset($_POST['bulk_delete_links'])) {
            if (!empty($_POST['del_ids']) && is_array($_POST['del_ids'])) {
                $ids = array_map('intval', $_POST['del_ids']);
                $in  = str_repeat('?,', count($ids) - 1) . '?';
                $pdo->prepare("DELETE FROM shared_links WHERE id IN ($in)")->execute($ids);
                $msg = "Bulk Deletion Executed.";
            } else {
                $msg = "No links selected for deletion.";
            }
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
        
        // REMOVE (SILENT DELETE)
        if (isset($_POST['remove_link'])) {
            $lid = $_POST['link_id'];
            $pdo->prepare("DELETE FROM shared_links WHERE id = ?")->execute([$lid]);
            $msg = "Link Removed from Queue (Not Banned).";
        }
        
 // BAN
        if (isset($_POST['ban_link'])) {
            $lid = $_POST['link_id'];
            
            // 1. Ban the specific clicked link immediately
            $stmt = $pdo->prepare("UPDATE shared_links SET status = 'banned' WHERE id = ?");
            $stmt->execute([$lid]);
            
            $url_val = $_POST['url_val'] ?? '';
            $ban_target = '';

            // 2. Smart Extraction: CAPTURE GROUP $m[1] ensures we get JUST the hash
            if (preg_match('/([a-z2-7]{56})(\.onion)?/i', $url_val, $m)) {
                $ban_target = $m[1]; // V3 Hash (56 chars)
            } elseif (preg_match('/([a-z2-7]{16})(\.onion)?/i', $url_val, $m)) {
                $ban_target = $m[1]; // V2 Hash (16 chars)
            } else {
                // Fallback: Use hostname for clearnet
                $parsed = parse_url($url_val);
                $ban_target = $parsed['host'] ?? $url_val;
            }
            
            $ban_target = strtolower(trim($ban_target));

            if (!empty($ban_target)) {
                // 3. Unique Constraint: Check if pattern already exists
                $dup = $pdo->prepare("SELECT id FROM banned_patterns WHERE pattern = ?");
                $dup->execute([$ban_target]);
                
                if (!$dup->fetch()) {
                    // Add to Automod
                    $pdo->prepare("INSERT INTO banned_patterns (pattern, reason) VALUES (?, 'Malicious Link')")
                        ->execute([$ban_target]);
                    
                    // 4. RETROACTIVE AUTO-BAN (The "Duplicate" Fix)
                    // Instantly ban ANY pending link that matches this new pattern
                    $retro_pat = "%" . $ban_target . "%";
                    $pdo->prepare("UPDATE shared_links SET status='banned' WHERE url LIKE ? AND status='pending'")
                        ->execute([$retro_pat]);
                        
                    $msg = "Link Banned. Pattern '$ban_target' added. Queue scrubbed of duplicates.";
                } else {
                    $msg = "Link Banned (Pattern '$ban_target' was already in Automod).";
                }
            } else {
                $msg = "Link Banned (Could not extract valid pattern).";
            }
        }
    } // <--- END LINK ACTIONS

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
            $raw_pat = trim($_POST['pattern']);
            $final_pat = '';

            // SMART NORMALIZATION
            // Capture Group 1 ($m[1]) isolates the hash from the .onion extension
            if (preg_match('/([a-z2-7]{56})(\.onion)?/i', $raw_pat, $m)) {
                $final_pat = $m[1]; 
            } 
            elseif (preg_match('/([a-z2-7]{16})(\.onion)?/i', $raw_pat, $m)) {
                $final_pat = $m[1];
            }
            else {
                $parsed = parse_url($raw_pat);
                $final_pat = $parsed['host'] ?? $raw_pat; 
            }

            $final_pat = strtolower(trim($final_pat));
          

            if ($final_pat) {
                // Check duplicate
                $dup = $pdo->prepare("SELECT id FROM banned_patterns WHERE pattern = ?");
                $dup->execute([$final_pat]);

                if (!$dup->fetch()) {
                    $pdo->prepare("INSERT INTO banned_patterns (pattern, reason) VALUES (?, ?)")
                        ->execute([$final_pat, $_POST['reason'] ?? 'Manual Ban']);
                    
                    // RETROACTIVE BAN: Scrub queue for this new manual pattern
                    $retro_pat = "%" . $final_pat . "%";
                    $pdo->prepare("UPDATE shared_links SET status='banned' WHERE url LIKE ? AND status='pending'")
                        ->execute([$retro_pat]);

                    $msg = "Pattern '$final_pat' Added & Queue Scrubbed.";
                } else {
                    $msg = "Pattern '$final_pat' already exists.";
                }
            }
        }
        if (isset($_POST['delete_pattern'])) {
            $pdo->prepare("DELETE FROM banned_patterns WHERE id = ?")->execute([$_POST['pattern_id']]);
            $msg = "Pattern Removed.";
        }
    }

    // 8. PERMISSIONS (OWNER ONLY)
    if ($tab === 'perms' && $_SESSION['rank'] >= 10) {
        if (isset($_POST['save_perms'])) {
            // [ADDED] Save moved settings
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('invite_min_rank', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$_POST['invite_min_rank'], $_POST['invite_min_rank']]);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('alert_new_user_rank', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$_POST['alert_new_user_rank'], $_POST['alert_new_user_rank']]);

            $perms = [
                'perm_chat_delete' => (int)$_POST['p_chat_del'],
                'perm_user_ban' => (int)$_POST['p_ban'],
                'perm_user_kick' => (int)$_POST['p_kick'],
                'perm_user_nuke' => (int)$_POST['p_nuke'],
                'perm_view_hidden' => (int)$_POST['p_hidden'],
                'perm_link_bypass' => (int)$_POST['p_link'],
                'perm_create_post' => (int)$_POST['p_post'],
                'perm_invite' => (int)$_POST['p_invite'],
                'perm_view_logs' => (int)$_POST['p_logs'],
                'perm_manage_users' => (int)$_POST['p_users'],
                'perm_chat_config' => (int)$_POST['p_chat_conf'],
                'perm_view_mod_panel' => (int)$_POST['p_mod_panel'],
                'perm_view_directory' => (int)$_POST['p_dir'],
                'perm_send_pm' => (int)$_POST['p_pm']
            ];
            $json = json_encode($perms);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('permissions_config', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$json, $json]);
            $msg = "Permissions Matrix Updated.";
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
        
        <?php if($my_rank >= 10): ?>
            <a href="?view=config" class="<?= $tab=='config'?'active':'' ?>">GENERAL CONFIG</a>
        <?php endif; ?>

        <?php if($my_rank >= $req_users): ?>
            <a href="?view=users" class="<?= $tab=='users'?'active':'' ?>">USER MANAGEMENT</a>
        <?php endif; ?>

        <a href="?view=posts" class="<?= $tab=='posts'?'active':'' ?>">POST MANAGEMENT</a>

        <?php if($my_rank >= $req_logs): ?>
            <a href="?view=logs" class="<?= $tab=='logs'?'active':'' ?>">SECURITY LOGS</a>
        <?php endif; ?>

        <?php if($my_rank >= $req_chat): ?>
            <a href="?view=chat" class="<?= $tab=='chat'?'active':'' ?>">CHAT CONTROL</a>
        <?php endif; ?>

        <a href="?view=links" class="<?= $tab=='links'?'active':'' ?>">LINK MANAGEMENT</a>

        <?php if($my_rank >= $req_automod): ?>
            <a href="?view=automod" class="<?= $tab=='automod'?'active':'' ?>">AUTOMOD</a>
        <?php endif; ?>

        <?php if($my_rank >= 10): ?>
            <a href="?view=perms" class="<?= $tab=='perms'?'active':'' ?>" style="color:#d19a66;">[ OWNER PERMS ]</a>
        <?php endif; ?>

        <a href="index.php" style="margin-top: 50px; border-top: 1px solid #333;">&lt; RETURN TO SITE</a>
    </div>

    <div class="main-panel">
        
<?php if($tab === 'config'): ?>
        <div class="panel-header"><h2 class="panel-title">System Configuration</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>
        
<form method="POST" enctype="multipart/form-data">
            
            <h3 style="color:#6a9c6a; font-size:0.9rem; margin:0 0 10px 0; border-bottom:1px solid #333;">ACCESS & REGISTRATION</h3>
            <div class="setting-grid">
                <div class="input-group">
                    <label style="color:#e06c75;">REGISTRATION</label>
                    <select name="reg_enabled" style="background:#111; color:#fff; border:1px solid #333; padding:8px;">
                        <option value="1" <?= ($settings['registration_enabled']??'1')=='1' ? 'selected' : '' ?>>OPEN</option>
                        <option value="0" <?= ($settings['registration_enabled']??'1')=='0' ? 'selected' : '' ?>>CLOSED</option>
                    </select>
                </div>
                <div class="input-group span-2">
                    <label>Registration Closed Message</label>
                    <input type="text" name="reg_msg" value="<?= htmlspecialchars($settings['registration_msg'] ?? "Registration Closed.") ?>">
                </div>
            </div>

            <h3 style="color:#6a9c6a; font-size:0.9rem; margin:15px 0 10px 0; border-bottom:1px solid #333;">CAPTCHA CONFIG</h3>
            <div class="setting-grid" style="grid-template-columns: repeat(4, 1fr);">
                <div class="input-group"><label>Grid W</label><input type="number" name="grid_w" value="<?= $settings['captcha_grid_w']?>"></div>
                <div class="input-group"><label>Grid H</label><input type="number" name="grid_h" value="<?= $settings['captcha_grid_h']?>"></div>
                <div class="input-group"><label>Cell (px)</label><input type="number" name="cell_size" value="<?= $settings['captcha_cell_size']?>"></div>
                <div class="input-group"><label>Sum Min</label><input type="number" name="min_sum" value="<?= $settings['captcha_min_sum']?>"></div>
                <div class="input-group"><label>Sum Max</label><input type="number" name="max_sum" value="<?= $settings['captcha_max_sum']?>"></div>
                <div class="input-group"><label>Active Min</label><input type="number" name="active_min" value="<?= $settings['captcha_active_min'] ?? 3 ?>"></div>
                <div class="input-group"><label>Active Max</label><input type="number" name="active_max" value="<?= $settings['captcha_active_max'] ?? 5 ?>"></div>
            </div>

            <h3 style="color:#6a9c6a; font-size:0.9rem; margin:15px 0 10px 0; border-bottom:1px solid #333;">VISUALS & CONTENT</h3>
            <div class="setting-grid">
                <div class="input-group">
                    <label>Site Theme</label>
                    <select name="site_theme" style="background:#111; color:#fff; border:1px solid #333; padding:8px; width:100%;">
                        <option value="" <?= ($settings['site_theme']??'')==''?'selected':'' ?>>Default</option>
                        <option value="theme-christmas" <?= ($settings['site_theme']??'')=='theme-christmas'?'selected':'' ?>>Christmas</option>
                        <option value="theme-spooky" <?= ($settings['site_theme']??'')=='theme-spooky'?'selected':'' ?>>Spooky</option>
                        <option value="theme-matrix" <?= ($settings['site_theme']??'')=='theme-matrix'?'selected':'' ?>>Matrix</option>
                    </select>
                </div>
                <div class="input-group">
                     <label>Max History</label>
                     <input type="number" name="max_history" value="<?= $settings['max_chat_history'] ?? 150 ?>">
                </div>
                 <div class="input-group">
                    <label>Show Online Count</label>
                    <select name="show_nodes" style="background:#111; color:#fff; border:1px solid #333; padding:8px; width:100%;">
                        <option value="1" <?= ($settings['show_online_nodes']??'1')=='1' ? 'selected' : '' ?>>YES</option>
                        <option value="0" <?= ($settings['show_online_nodes']??'1')=='0' ? 'selected' : '' ?>>NO</option>
                    </select>
                </div>
<div class="input-group span-full">
                    <label>Background Image (Upload)</label>
                    <input type="hidden" name="saved_bg_url" value="<?= htmlspecialchars($settings['site_bg_url']??'') ?>">
                    <input type="file" name="bg_upload" style="background:#111; border:1px solid #333; padding:5px; width:100%;">
                    <?php if(!empty($settings['site_bg_url'])): ?>
                        <div style="font-size:0.7rem; margin-top:5px;">
                            <label><input type="checkbox" name="remove_bg"> Remove: <?= htmlspecialchars($settings['site_bg_url']) ?></label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="setting-grid">
                <div class="input-group span-full">
                    <label>PGP Challenge Message</label>
                    <textarea name="pgp_msg" style="height: 60px;"><?= htmlspecialchars($settings['pgp_message'] ?? '') ?></textarea>
                </div>
                <div class="input-group span-full">
                    <label>Login Welcome (BBCode)</label>
                    <textarea name="login_msg" style="height: 60px;"><?= htmlspecialchars($settings['login_message'] ?? '') ?></textarea>
                </div>
                <div class="input-group span-full">
                    <label>Emoji Presets (CSV)</label>
                    <input type="text" name="emoji_presets" value="<?= htmlspecialchars($settings['chat_emoji_presets'] ?? '❤️,🔥,👍,💀') ?>">
                </div>

                <div class="input-group span-full">
                    <label>Palette Manager (Visual Editor)</label>
                    <div style="background:#080808; border:1px solid #333; padding:15px;">
                        
                        <div style="display:grid; grid-template-columns: 2fr 1fr 50px; gap:10px; margin-bottom:10px; color:#666; font-size:0.7rem; border-bottom:1px solid #222; padding-bottom:5px;">
                            <span>COLOR NAME</span><span>HEX SELECTOR</span><span>DEL</span>
                        </div>
                        
                        <?php 
                        $vis_pal = json_decode($settings['palette_json'] ?? '[]', true);
                        foreach($vis_pal as $name => $rgb): 
                            $hex = sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
                        ?>
                        <div style="display:grid; grid-template-columns: 2fr 1fr 50px; gap:10px; margin-bottom:8px;">
                            <input type="text" name="pal_names[]" value="<?= htmlspecialchars($name) ?>" placeholder="Name" style="margin:0;">
                            <input type="color" name="pal_hexs[]" value="<?= $hex ?>" style="padding:0; height:36px; width:100%; cursor:pointer; background:none; border:1px solid #333;">
                            <button type="submit" name="del_palette_key" value="<?= htmlspecialchars($name) ?>" style="background:#220505; color:#e06c75; border:1px solid #e06c75; cursor:pointer; font-weight:bold;">X</button>
                        </div>
                        <?php endforeach; ?>
                        
                        <div style="margin-top:20px; border-top:1px dashed #333; padding-top:15px;">
                            <div style="font-size:0.7rem; color:#6a9c6a; margin-bottom:8px; font-weight:bold;">+ ADD NEW COLOR</div>
                            <div style="display:grid; grid-template-columns: 2fr 1fr 50px; gap:10px;">
                                <input type="text" name="new_pal_name" placeholder="New Name (e.g. NeonCyan)" style="margin:0;">
                                <input type="color" name="new_pal_hex" value="#ffffff" style="padding:0; height:36px; width:100%; cursor:pointer; background:none; border:1px solid #333;">
                                <div style="display:flex; align-items:center; justify-content:center; color:#555; font-size:0.65rem;">(Save)</div>
                            </div>
                        </div>

                    </div>
                    <input type="hidden" name="palette" value="<?= htmlspecialchars($settings['palette_json'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 20px;">APPLY & SAVE PALETTE</button>
        </form>
        <?php endif; ?>

<?php if($tab === 'users'): ?>
        <div class="panel-header"><h2 class="panel-title">User Registry</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

        <div style="margin-bottom: 25px; border: 1px solid #333; background: #0b0b0b;">
            <div style="padding: 8px 15px; background: #111; border-bottom: 1px solid #222; color: #e06c75; font-size: 0.75rem; font-weight: bold;">
                ACTIVE PENALTIES (BANNED / MUTED / SLOWED)
            </div>
            <div style="padding: 10px;">
                <?php 
                $penalized = $pdo->query("SELECT id, username, is_banned, is_muted, slow_mode_override FROM users WHERE is_banned=1 OR is_muted=1 OR slow_mode_override > 0")->fetchAll();
                if(empty($penalized)): 
                ?>
                    <div style="color: #444; font-size: 0.7rem; font-style: italic;">No active penalties found. System Clean.</div>
                <?php else: foreach($penalized as $pu): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #222; padding: 5px 0;">
                        <span style="font-size: 0.8rem; color: #ccc;">
                            <?= htmlspecialchars($pu['username']) ?> <span style="color:#666; font-size:0.7rem;">[ID: <?= $pu['id'] ?>]</span>
                        </span>
                        <div style="display: flex; gap: 10px;">
                            <?php if($pu['is_banned']): ?>
                                <form method="POST" style="margin:0;"><input type="hidden" name="user_id" value="<?= $pu['id'] ?>"><button type="submit" name="quick_unban" class="badge" style="background:#220505; color:#e06c75; border:1px solid #e06c75; cursor:pointer;">UNBAN</button></form>
                            <?php endif; ?>
                            <?php if($pu['is_muted']): ?>
                                <form method="POST" style="margin:0;"><input type="hidden" name="user_id" value="<?= $pu['id'] ?>"><button type="submit" name="quick_unmute" class="badge" style="background:#1a1a05; color:#e5c07b; border:1px solid #e5c07b; cursor:pointer;">UNMUTE</button></form>
                            <?php endif; ?>
                            <?php if($pu['slow_mode_override'] > 0): ?>
                                <form method="POST" style="margin:0;"><input type="hidden" name="user_id" value="<?= $pu['id'] ?>"><button type="submit" name="quick_unslow" class="badge" style="background:#0f1a1a; color:#56b6c2; border:1px solid #56b6c2; cursor:pointer;">RESET SPEED (<?= $pu['slow_mode_override'] ?>s)</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div style="margin-bottom: 20px; background: #111; border: 1px solid #333; padding: 15px;">
            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="view" value="users">
                <input type="text" name="q" placeholder="Search Username..." value="<?= htmlspecialchars($_GET['q']??'') ?>" style="flex:1; background:#000; color:#fff; border:1px solid #444; padding:8px;">
                
                <select name="sort" style="background:#000; color:#fff; border:1px solid #444; padding:8px; width:auto;">
                    <option value="rank_desc" <?= ($_GET['sort']??'')=='rank_desc'?'selected':'' ?>>Rank (High-Low)</option>
                    <option value="rank_asc" <?= ($_GET['sort']??'')=='rank_asc'?'selected':'' ?>>Rank (Low-High)</option>
                    <option value="name_asc" <?= ($_GET['sort']??'')=='name_asc'?'selected':'' ?>>Alphabetical (A-Z)</option>
                    <option value="active_desc" <?= ($_GET['sort']??'')=='active_desc'?'selected':'' ?>>Last Active (Newest)</option>
                </select>
                
                <button type="submit" class="badge" style="background:#6a9c6a; color:#000; border:none; padding:8px 15px; cursor:pointer; font-weight:bold;">FILTER</button>
            </form>
        </div>
        
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
        
        <?php 
        // DYNAMIC QUERY BUILDING
        $q = trim($_GET['q'] ?? '');
        $sort = $_GET['sort'] ?? 'rank_desc';
        $sql = "SELECT * FROM users WHERE 1=1";
        $params = [];

        if($q !== '') {
            $sql .= " AND username LIKE ?";
            $params[] = "%$q%";
        }

        switch($sort) {
            case 'rank_asc': $sql .= " ORDER BY rank ASC, id ASC"; break;
            case 'name_asc': $sql .= " ORDER BY username ASC"; break;
            case 'active_desc': $sql .= " ORDER BY last_active DESC"; break;
            default: $sql .= " ORDER BY rank DESC, id ASC"; // rank_desc
        }
        
        $sql .= " LIMIT 100";
        $users_stmt = $pdo->prepare($sql);
        $users_stmt->execute($params);
        $users = $users_stmt->fetchAll();
        ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align:center;">ID</th>
                    <th style="width: 220px;">User / Rank</th>
                    <th>Style (CSS or BBCode)</th>
                    <th style="width: 160px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($users as $u): ?>
            <tr style="border-bottom: 1px solid #222;">
                <form method="POST">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                
                <td style="vertical-align:middle; text-align:center; color:#444; font-size:0.7rem;">
                    <?= $u['id'] ?>
                </td>
                
                <td style="vertical-align:middle; padding: 5px;">
                    <div style="display:flex; align-items:center; gap: 8px;">
                        <div style="position:relative; width:50px;">
                            <span style="position:absolute; left:3px; top:50%; transform:translateY(-50%); color:#444; font-size:0.55rem; pointer-events:none;">R</span>
                            <input type="number" name="new_rank" value="<?= $u['rank'] ?>" 
                                   style="width:100%; padding-left:10px; padding-right:2px; background:#0b0b0b; border:1px solid #333; color:#d19a66; text-align:center; font-size:0.75rem; height:24px; box-sizing:border-box;">
                        </div>

                        <div style="line-height: 1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <a href="profile.php?id=<?= $u['id'] ?>" target="_blank" style="text-decoration:none;">
                            <?php
                                $raw_style = $u['chat_color'] ?? '';
                                $display_name = htmlspecialchars($u['username']);
                                
                                if (strpos($raw_style, '{u}') !== false || (str_starts_with(trim($raw_style), '[') && str_ends_with(trim($raw_style), ']'))) {
                                    $processed = str_replace('{u}', $display_name, $raw_style);
                                    if (strpos($raw_style, '{u}') === false) $processed = $raw_style . $display_name; 
                                    echo parse_bbcode($processed);
                                } elseif (strpos($raw_style, ':') !== false || strpos($raw_style, ';') !== false) {
                                    echo "<span style='$raw_style'>$display_name</span>";
                                } else {
                                    $c = $raw_style ?: '#ccc';
                                    echo "<span style='color:$c'>$display_name</span>";
                                }
                            ?>
                            </a>
                            <div style="font-size:0.6rem; color:#555; margin-top:2px;">
                                Active: <?= $u['last_active'] ? date('m-d H:i', strtotime($u['last_active'])) : 'Never' ?>
                            </div>
                        </div>
                    </div>
                </td>
                
                <td style="vertical-align:middle; padding: 5px;">
                    <input type="text" name="chat_color" value="<?= htmlspecialchars($u['chat_color']??'') ?>" 
                           placeholder="CSS or [b]{u}[/b]" 
                           style="width:100%; height:26px; background:#0b0b0b; border:1px solid #333; color:#bbb; font-size:0.7rem; padding:0 5px; font-family:monospace; box-sizing:border-box;">
                </td>
                
                <td style="vertical-align:middle; padding: 5px; text-align:right;">
                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:4px; white-space:nowrap;">
                        
                        <button type="submit" name="update_rank" title="Save Changes" 
                                style="height:24px; background:#0f1a0f; border:1px solid #3d6e3d; color:#6a9c6a; cursor:pointer; padding:0 8px; font-size:0.65rem; font-weight:bold; line-height:22px;">
                            SAVE
                        </button>

                        <?php if($u['rank'] < 10): ?>
                            <button type="submit" name="ban_user" title="Ban & Kick" onclick="return confirm('WARNING: Ban User <?= htmlspecialchars($u['username']) ?>?');"
                                    style="height:24px; background:#1a0f0f; border:1px solid #6e3d3d; color:#e06c75; cursor:pointer; padding:0 8px; font-size:0.65rem; font-weight:bold; line-height:22px;">
                                BAN
                            </button>

                            <button type="submit" name="delete_user" title="Wipe User Data" onclick="return confirm('CRITICAL: PERMANENTLY DELETE USER <?= htmlspecialchars($u['username']) ?>?');"
                                    style="border:none; background:#111; color:#555; cursor:pointer; font-weight:bold; font-size:0.65rem; padding:0 8px; height:24px; line-height:24px; border:1px solid #333;">
                                WIPE
                            </button>
                        <?php endif; ?>

                    </div>
                </td>
                </form>
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
            <?php
                // TRUNCATE TITLE (Safe Mode)
                $short_title = strip_tags($p['title']);
                if (strlen($short_title) > 40) $short_title = substr($short_title, 0, 40) . "...";
            ?>
            <tr>
                <td><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($short_title) ?></td>
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
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

        <div style="background:#111; border:1px solid #333; padding:15px; margin-bottom:20px;">
            <h3 style="color:#6a9c6a; font-size:0.9rem; margin-top:0;">GLOBAL SETTINGS</h3>
            <form method="POST" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div>
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:15px; color:#e06c75; font-weight:bold;">
                        <input type="checkbox" name="chat_locked" value="1" <?= ($settings['chat_locked']??'0')=='1' ? 'checked' : '' ?>>
                        LOCK CHAT SIGNAL
                    </label>
                    <div class="input-group">
                        <label>Lock Bypass Rank (Min)</label>
                        <input type="number" name="chat_lock_req" value="<?= $settings['chat_lock_req'] ?? 9 ?>" min="1" max="10" style="width:100px;">
                    </div>
                    <div class="input-group">
                        <label>Slow Mode (Seconds)</label>
                        <input type="number" name="chat_slow_mode" value="<?= $settings['chat_slow_mode'] ?? 0 ?>" min="0" style="width:100px;">
                    </div>
                </div>
                <div>
                    <div class="input-group">
                        <label>Pinned Notice (BBCode Allowed)</label>
                        <textarea name="chat_pinned_msg" style="height:60px;"><?= htmlspecialchars($settings['chat_pinned_msg'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="input-group">
                        <label>Notice Style</label>
                        <select name="chat_pin_style" style="background:#000; color:#fff; border:1px solid #444; width:100%; padding:8px;">
                            <option value="INFO" <?= ($settings['chat_pin_style']??'')=='INFO'?'selected':'' ?>>Blue [INFO]</option>
                            <option value="WARN" <?= ($settings['chat_pin_style']??'')=='WARN'?'selected':'' ?>>Red [WARNING]</option>
                            <option value="CRIT" <?= ($settings['chat_pin_style']??'')=='CRIT'?'selected':'' ?>>Purple [CRITICAL]</option>
                            <option value="MAINT" <?= ($settings['chat_pin_style']??'')=='MAINT'?'selected':'' ?>>Gold [MAINTENANCE]</option>
                            <option value="SUCCESS" <?= ($settings['chat_pin_style']??'')=='SUCCESS'?'selected':'' ?>>Green [SUCCESS]</option>
                            <option value="CUSTOM" <?= ($settings['chat_pin_style']??'')=='CUSTOM'?'selected':'' ?>>[ CUSTOM ]</option>
                            <option value="NONE" <?= ($settings['chat_pin_style']??'')=='NONE'?'selected':'' ?>>[ NONE ]</option>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                        <div class="input-group" style="margin:0;">
                            <label>Custom Hex</label>
                            <input type="text" name="chat_pin_custom_color" value="<?= htmlspecialchars($settings['chat_pin_custom_color'] ?? '#6a9c6a') ?>" placeholder="#ffffff">
                        </div>
                        <div class="input-group" style="margin:0;">
                            <label>Custom Label/Emoji</label>
                            <input type="text" name="chat_pin_custom_emoji" value="<?= htmlspecialchars($settings['chat_pin_custom_emoji'] ?? '') ?>" placeholder="e.g. 📢">
                        </div>
                    </div>

                    <button type="submit" name="save_chat_config" class="btn-primary" style="margin-top:5px;">APPLY CONFIG</button>
                </div>
            </form>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
            <div style="background:#111; border:1px solid #333; padding:15px;">
                <h3 style="color:#d19a66; font-size:0.9rem; margin-top:0;">WIPE USER HISTORY</h3>
                <form method="POST" onsubmit="return confirm('Delete ALL messages from this user?');">
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="wipe_username" placeholder="Username" required style="background:#000; color:#fff; border:1px solid #444; padding:8px;">
                        <button type="submit" name="wipe_user_chat" class="badge" style="background:#e06c75; border:none; color:#000; padding:8px 15px; cursor:pointer; font-weight:bold;">WIPE</button>
                    </div>
                </form>
            </div>
            
            <div style="background:#111; border:1px solid #333; padding:15px;">
                <h3 style="color:#e06c75; font-size:0.9rem; margin-top:0;">NUCLEAR OPTIONS</h3>
                <div style="display:flex; gap:10px;">
                    <form method="POST" onsubmit="return confirm('Fix Database? Wipes chat history.');"><button type="submit" name="repair_chat_db" class="badge" style="background:#6a9c6a; color:#000; border:none; padding:8px 15px; cursor:pointer;">REPAIR DB</button></form>
                    <form method="POST" onsubmit="return confirm('Wipe ALL chat messages?');"><button type="submit" name="purge_chat" class="badge" style="background:#e06c75; color:#000; border:none; padding:8px 15px; cursor:pointer;">PURGE ALL</button></form>
                </div>
            </div>
        </div>

        <div style="margin-top:20px; padding: 20px; background: #111; border: 1px solid #333;">
            <h3 style="margin-top:0; color:#ccc; font-size:0.9rem;">SYSTEM BROADCAST</h3>
            <form method="POST">
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <select name="sys_msg_type" style="background:#000; color:#fff; border:1px solid #444; padding:10px; width:150px;">
                        <option value="INFO">BLUE INFO</option>
                        <option value="WARNING">RED ALERT</option>
                        <option value="SUCCESS">GREEN SUCCESS</option>
                        <option value="CRITICAL">PURPLE CRITICAL</option>
                        <option value="MAINT">ORANGE MAINT</option>
                        <option value="BLANK">BLANK NOTICE</option>
                    </select>
                    <input type="text" name="sys_msg_text" placeholder="Message content..." required style="flex-grow:1; background:#000; color:#fff; border:1px solid #444; padding:10px;">
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <span style="font-size:0.7rem; color:#666;">CUSTOM OVERRIDE:</span>
                    <input type="text" name="sys_custom_label" placeholder="Label (e.g. NOTICE)" style="width:140px; background:#000; border:1px solid #333; color:#fff; padding:5px; font-size:0.75rem;">
                    <input type="text" name="sys_custom_hex" placeholder="Hex (e.g. #ff0000)" style="width:130px; background:#000; border:1px solid #333; color:#fff; padding:5px; font-size:0.75rem;">
                    <button type="submit" name="send_sys_msg" class="btn-primary" style="width:auto; padding:5px 20px; height:32px; font-size:0.7rem;">BROADCAST</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

<?php if($tab === 'links'): ?>
    <?php 
        $cats = [];
        try { $cats = $pdo->query("SELECT * FROM link_categories ORDER BY display_order ASC")->fetchAll(); } catch(Exception $e) {}
    ?>
    
    <div style="margin-bottom:20px; border:1px solid #333; padding:10px; background:#0f0f0f; display:flex; gap:20px; align-items:flex-start;">
        <div style="flex:1;">
            <h3 style="margin:0 0 10px 0; color:#d19a66; font-size:0.8rem;">MANAGE CATEGORIES</h3>
            <div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:10px;">
                <?php foreach($cats as $c): ?>
                    <form method="POST" style="display:inline-block; margin:0;">
                        <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                        <span class="badge" style="border:1px solid #444; padding:4px 8px;">
                            <?= htmlspecialchars($c['name']) ?>
                            <button type="submit" name="del_cat" style="background:none; border:none; color:#e06c75; font-weight:bold; cursor:pointer; margin-left:5px;">x</button>
                        </span>
                    </form>
                <?php endforeach; ?>
            </div>
            <form method="POST" style="display:flex; gap:5px;">
                <input type="text" name="cat_name" placeholder="New Category Name" required style="width:150px; padding:5px; background:#000; border:1px solid #333; color:#fff; font-size:0.7rem;">
                <button type="submit" name="add_cat" class="badge" style="background:#6a9c6a; color:#000; cursor:pointer; border:none;">ADD</button>
            </form>
        </div>
        
        <div style="border-left:1px solid #333; padding-left:20px;">
             <h3 style="margin:0 0 10px 0; color:#6a9c6a; font-size:0.8rem;">BULK ACTIONS</h3>
             <form method="POST" onsubmit="return confirm('Approve ALL pending?');">
                 <button type="submit" name="approve_all" class="btn-primary" style="width:auto; background:#6a9c6a; color:#000;">APPROVE ALL PENDING</button>
             </form>
        </div>
    </div>

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
<td style="vertical-align:middle; text-align:right;">
                    <form method="POST" style="display:flex; gap:6px; align-items:center; justify-content:flex-end; margin:0;">
                        <input type="hidden" name="link_id" value="<?= $l['id'] ?>">
                        <input type="hidden" name="url_val" value="<?= htmlspecialchars($l['url']) ?>">
                        
                        <input type="text" name="link_title" placeholder="Title..." 
                               style="width:90px; background:transparent; border:none; border-bottom:1px solid #333; color:#e5c07b; font-size:0.7rem; padding:2px 5px; outline:none;">
                               
                        <input type="text" name="approval_msg" placeholder="Msg..." 
                               style="width:80px; background:transparent; border:none; border-bottom:1px solid #333; color:#888; font-size:0.7rem; padding:2px 5px; outline:none;">
                        
                        <div style="width:1px; height:15px; background:#222; margin:0 2px;"></div>

                        <button type="submit" name="approve_link" title="Approve"
                                style="background:#0f1a0f; border:1px solid #6a9c6a; color:#6a9c6a; cursor:pointer; padding:3px 8px; font-size:0.65rem; font-weight:bold; font-family:monospace;">OK</button>
                        
                        <button type="submit" name="remove_link" title="Remove (Silent)"
                                style="background:#161616; border:1px solid #444; color:#888; cursor:pointer; padding:3px 8px; font-size:0.65rem; font-weight:bold; font-family:monospace;">RM</button>
                        
                        <button type="submit" name="ban_link" title="Ban Domain"
                                style="background:#1a0f0f; border:1px solid #e06c75; color:#e06c75; cursor:pointer; padding:3px 8px; font-size:0.65rem; font-weight:bold; font-family:monospace;">BAN</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="panel-header" style="align-items:center;">
            <h2 class="panel-title">Active Database</h2>
            <form method="GET" style="display:flex; gap:10px;">
                <input type="hidden" name="view" value="links">
                <select name="db_cat" onchange="this.form.submit()" style="width:auto; padding:5px; font-size:0.7rem;">
                    <option value="">[ VIEW ALL ]</option>
                    <?php foreach($cats as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (isset($_GET['db_cat']) && $_GET['db_cat'] == $c['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="badge">FILTER</button></noscript>
            </form>
        </div>

        <form method="POST">
        <div style="margin-bottom:10px; padding:8px; background:#111; border:1px solid #333; display:flex; justify-content:space-between; align-items:center;">
            <div style="font-size:0.7rem; color:#666;">SELECT via Checkbox to Bulk Delete</div>
            <div style="display:flex; gap:10px;">
                <button type="submit" name="save_all_links" class="badge" style="background:#222; border:1px solid #444; color:#ccc; padding:5px 10px; cursor:pointer;">SAVE ALL CHANGES</button>
                <button type="submit" name="bulk_delete_links" class="badge" style="background:#e06c75; border:none; color:#000; padding:5px 10px; cursor:pointer; font-weight:bold;" onclick="return confirm('DELETE SELECTED?');">BULK DELETE</button>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Content (Title & URL)</th>
                    <th style="width: 80px; text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            // FILTER LOGIC
            $db_cat_filter = $_GET['db_cat'] ?? '';
            $sql_db = "SELECT * FROM shared_links WHERE status = 'approved'";
            $params_db = [];
            
            if ($db_cat_filter !== '') {
                $sql_db .= " AND category_id = ?";
                $params_db[] = $db_cat_filter;
            }
            
            $sql_db .= " ORDER BY created_at DESC LIMIT 100";
            $stmt_db = $pdo->prepare($sql_db);
            $stmt_db->execute($params_db);
            $approved = $stmt_db->fetchAll();

            foreach($approved as $l): 
                $lid = $l['id'];
            ?>
            <tr>
                <td style="vertical-align:top; padding-top:12px;">
                    <input type="checkbox" name="del_ids[]" value="<?= $lid ?>">
                </td>
                <td>
                    <input type="text" name="links[<?= $lid ?>][title]" value="<?= htmlspecialchars($l['title'] ?? '') ?>" placeholder="No Title" style="width:100%; background:transparent; border:none; border-bottom:1px dashed #333; color:#d19a66; font-weight:bold; margin-bottom:5px;">
                    
                    <div style="display:flex; gap:10px; align-items:center;">
                        <select name="links[<?= $lid ?>][cat]" style="background:#111; color:#888; border:1px solid #333; font-size:0.65rem; padding:2px; width:auto;">
                            <option value="">[ Uncategorized ]</option>
                            <?php foreach($cats as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($l['category_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?= htmlspecialchars($l['url']) ?>" target="_blank" style="font-size:0.7rem; color:#555; font-family:monospace; text-decoration:none; border-bottom:1px dotted #444; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:350px; display:inline-block;">
                            <?= htmlspecialchars($l['url']) ?>
                        </a>
                    </div>
                </td>
                <td style="text-align:right; vertical-align:middle;">
                    <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                         <button type="submit" name="update_link" value="<?= $lid ?>" class="badge" style="background:#222; border:1px solid #444; color:#6a9c6a; cursor:pointer; width:50px;">SAVE</button>
                         <button type="submit" name="delete_link" value="<?= $lid ?>" class="badge" style="background:#332; border:1px solid #444; color:#e06c75; cursor:pointer; width:50px;" onclick="return confirm('Delete this link?');">DEL</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </form>

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
        <h3 style="color:#d19a66; font-size:0.9rem; margin-top:30px; margin-bottom:10px;">DATABASE BAN HISTORY (INDIVIDUAL LINKS)</h3>
        <div style="background:#111; padding:15px; border:1px solid #333;">
            <p style="font-size:0.7rem; color:#666; margin-top:0;">
                These specific links were banned individually. They are not in the "Patterns" list above, but they still block the user.
            </p>
            
            <?php 
            // Handle Unban Action
            if (isset($_POST['unban_history_link'])) {
                $pdo->prepare("DELETE FROM shared_links WHERE id = ?")->execute([$_POST['hl_id']]);
                echo "<div class='success'>Link history cleared. User can now repost this.</div>";
            }
// Handle "Convert to Pattern" Action
            if (isset($_POST['convert_to_pattern'])) {
                $raw = $_POST['hl_url'];
                $pat = $raw;
                
                // Smart Extract Logic (Force $m[1])
                if (preg_match('/([a-z2-7]{56})(\.onion)?/i', $raw, $m)) $pat = $m[1];
                elseif (preg_match('/([a-z2-7]{16})(\.onion)?/i', $raw, $m)) $pat = $m[1];
                else { $p = parse_url($raw); $pat = $p['host'] ?? $raw; }
                
                // Check dupes
                $chk = $pdo->prepare("SELECT id FROM banned_patterns WHERE pattern = ?");
                $chk->execute([$pat]);
                if(!$chk->fetch()) {
                    $pdo->prepare("INSERT INTO banned_patterns (pattern, reason) VALUES (?, 'Converted from History')")->execute([$pat]);
                    echo "<div class='success'>Added '$pat' to Global Blacklist.</div>";
                } else {
                    echo "<div class='error'>Pattern '$pat' is already in the global list.</div>";
                }
            }
            ?>

            <table class="data-table">
                <thead><tr><th>Original Link</th><th>Posted By</th><th>Action</th></tr></thead>
                <tbody>
                <?php 
                $zombies = $pdo->query("SELECT * FROM shared_links WHERE status = 'banned' ORDER BY id DESC")->fetchAll();
                if(empty($zombies)) echo "<tr><td colspan='3' style='text-align:center; color:#444; padding:10px;'>No legacy bans found. Clean.</td></tr>";
                
                foreach($zombies as $z): ?>
                <tr>
                    <td style="color:#e06c75; font-family:monospace; word-break:break-all;">
                        <?= htmlspecialchars($z['url']) ?>
                    </td>
                    <td style="color:#888;"><?= htmlspecialchars($z['posted_by']) ?></td>
                    <td>
                        <form method="POST" style="display:flex; gap:5px;">
                            <input type="hidden" name="hl_id" value="<?= $z['id'] ?>">
                            <input type="hidden" name="hl_url" value="<?= htmlspecialchars($z['url']) ?>">
                            
                            <button type="submit" name="unban_history_link" title="Remove Ban"
                                    style="background:#1a1a1a; border:1px solid #444; color:#ccc; cursor:pointer; padding:3px 8px; font-size:0.65rem; font-weight:bold;">
                                UNBAN
                            </button>
                            
                            <button type="submit" name="convert_to_pattern" title="Move to Blacklist"
                                    style="background:#1a0f0f; border:1px solid #e06c75; color:#e06c75; cursor:pointer; padding:3px 8px; font-size:0.65rem; font-weight:bold;">
                                ADD RULE
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if($tab === 'perms' && $_SESSION['rank'] >= 10): ?>
        <?php
            $def_perms = [
                'perm_chat_delete' => 5, 'perm_user_ban' => 9, 'perm_user_kick' => 5,
                'perm_user_nuke' => 10, 'perm_view_hidden' => 9, 'perm_link_bypass' => 9,
                'perm_create_post' => 9, 'perm_invite' => 5, 'perm_view_mod_panel' => 9
            ];
            $cur_perms = json_decode($settings['permissions_config'] ?? '', true) ?? $def_perms;
        ?>
        <div class="panel-header"><h2 class="panel-title">Clearance Level Configuration</h2></div>
        <?php if($msg): ?><div class="success"><?= $msg ?></div><?php endif; ?>

        <div style="background:#111; border:1px solid #333; padding:15px;">
            <p style="color:#666; font-size:0.75rem; margin-top:0;">
                Define the minimum Rank required to perform specific system actions. 
                <br><strong style="color:#e06c75;">WARNING: Setting values too low may compromise security.</strong>
            </p>
            <form method="POST" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                
                <div class="input-group" style="margin:0;">
                    <label style="color:#d19a66;">BAN USERS</label>
                    <input type="number" name="p_ban" value="<?= $cur_perms['perm_user_ban'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#d19a66;">NUKE ACCOUNT</label>
                    <input type="number" name="p_nuke" value="<?= $cur_perms['perm_user_nuke'] ?? 10 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#e5c07b;">KICK USERS</label>
                    <input type="number" name="p_kick" value="<?= $cur_perms['perm_user_kick'] ?? 5 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#e5c07b;">DELETE MSGS</label>
                    <input type="number" name="p_chat_del" value="<?= $cur_perms['perm_chat_delete'] ?? 5 ?>" min="1" max="10">
                </div>
                
                <div class="input-group" style="margin:0;">
                    <label>GENERATE INVITES</label>
                    <input type="number" name="p_invite" value="<?= $cur_perms['perm_invite'] ?? 5 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label>CREATE POSTS</label>
                    <input type="number" name="p_post" value="<?= $cur_perms['perm_create_post'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label>SEND PMs</label>
                    <input type="number" name="p_pm" value="<?= $cur_perms['perm_send_pm'] ?? 1 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label>BYPASS LINKS</label>
                    <input type="number" name="p_link" value="<?= $cur_perms['perm_link_bypass'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label>VIEW HIDDEN</label>
                    <input type="number" name="p_hidden" value="<?= $cur_perms['perm_view_hidden'] ?? 9 ?>" min="1" max="10">
                </div>

                <div class="input-group" style="margin:0;">
                    <label style="color:#e06c75;">VIEW LOGS</label>
                    <input type="number" name="p_logs" value="<?= $cur_perms['perm_view_logs'] ?? 10 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#d19a66;">MANAGE USERS</label>
                    <input type="number" name="p_users" value="<?= $cur_perms['perm_manage_users'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#56b6c2;">CHAT CONFIG</label>
                    <input type="number" name="p_chat_conf" value="<?= $cur_perms['perm_chat_config'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#e06c75;">MOD PANEL ACCESS</label>
                    <input type="number" name="p_mod_panel" value="<?= $cur_perms['perm_view_mod_panel'] ?? 9 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label style="color:#6a9c6a;">VIEW DIRECTORY</label>
                    <input type="number" name="p_dir" value="<?= $cur_perms['perm_view_directory'] ?? 3 ?>" min="1" max="10">
                </div>
                
                <div class="input-group" style="margin:0;">
                    <label>MIN INVITE RANK</label>
                    <input type="number" name="invite_min_rank" value="<?= $settings['invite_min_rank'] ?? 5 ?>" min="1" max="10">
                </div>
                <div class="input-group" style="margin:0;">
                    <label>NEW USER ALERT</label>
                    <input type="number" name="alert_new_user_rank" value="<?= $settings['alert_new_user_rank'] ?? 9 ?>" min="1" max="10">
                </div>

                <div style="grid-column: 1 / span 2; margin-top:5px;">
                    <button type="submit" name="save_perms" class="btn-primary" style="background:#1a0505; border-color:#e06c75; color:#e06c75; width:100%; height:30px; padding:0;">UPDATE MATRIX</button>
                </div>
                <div style="grid-column: 3 / span 2; margin-top:5px; display:flex; align-items:center; justify-content:center; color:#444; font-size:0.6rem; border:1px solid #222;">
                    SECURE_V2 // PERM_ISOLATION_ACTIVE
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>