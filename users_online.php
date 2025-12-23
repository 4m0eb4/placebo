<?php
session_start();
require 'db_config.php';
require 'bbcode.php'; // Required for username effects

// Auth Check
if (!isset($_SESSION['fully_authenticated'])) exit;

$my_id = $_SESSION['user_id'] ?? 0;
$my_rank = $_SESSION['rank'] ?? 0;

// --- 1. INSTANT HEARTBEAT (FORCE UPDATE) ---
// We run this immediately so the query below captures 'YOU' as active.
try {
    if (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
        $pdo->prepare("UPDATE guest_tokens SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['guest_token_id']]);
    } else {
        $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$my_id]);
    }
} catch (Exception $e) {}

// --- 1b. CHECK INBOX (NEW) ---
$unread_count = 0;
try {
    $stmt_pm = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0");
    $stmt_pm->execute([$my_id]);
    $unread_count = $stmt_pm->fetchColumn();
} catch (Exception $e) {}

// --- 2. FETCH & FILTER USERS ---
$display_users = [];
try {
    // Fetch Custom Rank Names
    $rank_map = [10=>'OWNER', 9=>'ADMIN', 5=>'VIP', 1=>'USER'];
    $r_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'rank_config'");
    if($json = $r_stmt->fetchColumn()) { 
        $decoded = json_decode($json, true);
        if($decoded) $rank_map = $decoded + $rank_map;
    }

    // STRICTER FILTER: Only active within 15 minutes (Real-time feel)
    $stmt = $pdo->query("
        SELECT id, username, rank, chat_color, user_status, show_online, last_active,
               (CASE WHEN last_active > (NOW() - INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as is_live
        FROM users 
        WHERE last_active > (NOW() - INTERVAL 15 MINUTE)
        AND is_banned = 0
        ORDER BY is_live DESC, last_active DESC
    ");
    $all_users = $stmt->fetchAll();

    foreach($all_users as $u) {
        $show = false;
        
        // VISIBILITY LOGIC:
        // 1. It's Me (Always show myself)
        if ($u['id'] == $my_id) $show = true;
        // 2. I am Admin (I see everyone)
        elseif ($my_rank >= 9) $show = true;
        // 3. User is Public (show_online is ON)
        elseif ($u['show_online'] == 1) $show = true;
        // 4. User is Staff (Rank < 10 but maybe hidden? usually admins are visible to each other)
        
        if ($show) {
            $display_users[] = $u;
        }
    }

} catch (Exception $e) {
    // Database error
}

// --- 3. FETCH GUESTS ---
$active_guests = [];
try {
    $stmt_g = $pdo->prepare("
        SELECT id, guest_username as username, '#888888' as color_hex
        FROM guest_tokens 
        WHERE last_active > (NOW() - INTERVAL 5 MINUTE)
        AND status = 'active'
        ORDER BY guest_username ASC
    ");
    $stmt_g->execute();
    $active_guests = $stmt_g->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="30">
    <style>
        /* STRICT RESET */
        * { box-sizing: border-box; }
        html, body { 
            margin: 0; padding: 0; 
            background: #0d0d0d; 
            font-family: monospace; 
            color: #ccc; 
            overflow-x: hidden;
        }

        /* CONTAINER */
        .list-container { padding: 0; margin: 0; }

        /* SECTION HEADERS */
        .section-head {
            background: #111;
            color: #555;
            font-size: 0.65rem;
            padding: 8px 12px;
            border-bottom: 1px solid #222;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
        }
        .refresh-link { color: #6a9c6a; text-decoration: none; cursor: pointer; }
        .refresh-link:hover { color: #fff; }
        
        /* INBOX LINK STYLE */
        .inbox-link { text-decoration: none; margin-right: 15px; font-weight: bold; font-size: 0.65rem; }
        .inbox-read { color: #666; }
        .inbox-unread { color: #e06c75 !important; border-bottom: 1px solid #e06c75; animation: pulse-text 2s infinite; }
        @keyframes pulse-text { 0% {opacity:1;} 50% {opacity:0.5;} 100% {opacity:1;} }

        /* ROWS */
        .refresh-link { color: #6a9c6a; text-decoration: none; cursor: pointer; }
        .refresh-link:hover { color: #fff; }

        /* ROWS */
        .row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #1a1a1a;
            background: #0d0d0d;
        }
        .row:hover { background: #131313; }

        /* INFO COLUMN */
        .info-col { display: flex; flex-direction: column; gap: 4px; }
        
        /* UPDATED: Larger Text Sizes */
        .username-link { font-size: 0.95rem; font-weight: bold; text-decoration: none; letter-spacing: 0.5px; }
        
        .status-line { font-size: 0.75rem; color: #666; display: flex; align-items: center; gap: 6px; }
        
        .pulse {
            display: inline-block; width: 6px; height: 6px; 
            background: #6a9c6a; border-radius: 50%;
            box-shadow: 0 0 4px #6a9c6a;
        }

        /* BADGES */
        .badge { 
            font-size: 0.55rem; padding: 1px 4px; border: 1px solid #333; 
            border-radius: 2px; margin-left: 5px; color: #666; 
        }
        .badge-10 { border-color: #d19a66; color: #d19a66; } /* Gold */
        .badge-9 { border-color: #6a9c6a; color: #6a9c6a; }  /* Green */
        .badge-5 { border-color: #56b6c2; color: #56b6c2; }  /* Blue */

        /* ACTION BUTTONS */
        .actions-col { display: flex; gap: 5px; }
        .btn {
            background: #050505; border: 1px solid #222; color: #666;
            font-size: 0.6rem; padding: 3px 6px; cursor: pointer; text-decoration: none;
        }
        .btn:hover { color: #ccc; border-color: #444; }
        .btn-kill { color: #823; border-color: #311; }
        .btn-kill:hover { background: #e06c75; color: #000; border-color: #e06c75; }

    </style>
</head>
<body>

    <div class="list-container">
<div class="section-head">
            <span style="font-size: 0.9rem; font-weight: bold; letter-spacing: 1px;">NODES (<?= count($display_users) ?>)</span>
            <div>
                <?php if(!isset($_SESSION['is_guest']) || !$_SESSION['is_guest']): ?>
                <a href="pm.php" target="_blank" class="inbox-link <?= $unread_count > 0 ? 'inbox-unread' : 'inbox-read' ?>">
                    [ INBOX<?= $unread_count > 0 ? ":$unread_count" : '' ?> ]
                </a>
                <?php endif; ?>
                <a href="users_online.php" class="refresh-link">[ REFRESH ]</a>
            </div>
        </div>
        
        <?php if(empty($display_users)): ?>
            <div class="row" style="color:#444; font-style:italic; font-size:0.7rem;">No signals detected.</div>
        <?php endif; ?>

       <?php foreach($display_users as $u): ?>
            <?php 
                $opacity = $u['is_live'] ? '1.0' : '0.4'; 
                
                // ROBUST STYLE PARSER (Supports BBCode Templates)
                $raw_s = $u['chat_color'] ?? '';
                $raw_s = str_ireplace(['url(', 'http', 'ftp', 'expression'], '', $raw_s);
                
                $inner_html = htmlspecialchars($u['username']);
                $wrapper_style = "";

                // 1. Template Check ({u} or [tags])
                if (strpos($raw_s, '{u}') !== false || (str_starts_with(trim($raw_s), '[') && str_ends_with(trim($raw_s), ']'))) {
                    $processed = str_replace('{u}', $u['username'], $raw_s);
                    // Fallback: Append if {u} missing but tags exist
                    if (strpos($raw_s, '{u}') === false) $processed = $raw_s . $u['username'];
                    $inner_html = parse_bbcode($processed);
                } else {
                    // 2. CSS or Hex
                    if (strpos($raw_s, ';') !== false || strpos($raw_s, ':') !== false) {
                        $wrapper_style = $raw_s;
                    } else {
                        $wrapper_style = "color: $raw_s";
                    }
                }
            ?>
            <div class="row" style="opacity: <?= $opacity ?>;">
                <div class="info-col">
                    <div>
                        <a href="profile.php?id=<?= $u['id'] ?>" target="_blank" class="username-link" style="<?= $wrapper_style ?>">
                            <?= $inner_html ?>
                        </a>
                        <?php if(!$u['is_live']): ?><span style="font-size:0.6rem; color:#444;">[IDLE]</span><?php endif; ?>
                        <span class="badge badge-<?= $u['rank'] ?>"><?= htmlspecialchars(strtoupper($rank_map[$u['rank']] ?? 'L'.$u['rank'])) ?></span>
                    </div>
                    <?php if(!empty($u['user_status'])): ?>
                        <div class="status-line"><span class="pulse"></span> <?= htmlspecialchars($u['user_status']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="actions-col">
                    <?php if($u['id'] != $my_id): ?>
                        <a href="pm.php?to=<?= $u['id'] ?>" target="_blank" class="btn">PM</a>
                    <?php endif; ?>

                    <?php 
                    // FETCH PERMS
                    $sys_perms = [];
                    try {
                        $p_res = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'")->fetchColumn();
                        $sys_perms = json_decode($p_res, true) ?? [];
                    } catch(Exception $e){}
                    
                    $req_kick = $sys_perms['perm_user_kick'] ?? 9; 
                    $req_ban  = $sys_perms['perm_user_ban'] ?? 9; 
                    ?>

                    <?php if($my_rank >= $req_kick && $u['rank'] < $my_rank): ?>
                         <form action="admin_exec.php" method="POST" style="display:flex; align-items:center; gap:3px; margin:0;">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="target_name" value="<?= htmlspecialchars($u['username']) ?>">
                            <input type="hidden" name="return_to" value="users_online.php">
                            
                            <button type="submit" name="action_kick" class="btn btn-kill" title="Kick">K</button>
                            <button type="submit" name="action_mute" class="btn btn-kill" style="color:#e5c07b; border-color:#e5c07b;" title="Mute">M</button>
                            <button type="submit" name="action_slow" class="btn btn-kill" style="color:#56b6c2; border-color:#56b6c2;" title="Slow">S</button>
                            
                            <?php if($my_rank >= $req_ban): ?>
                                <label style="font-size:0.5rem; color:#555; display:flex; align-items:center; margin-left:3px;">
                                    <input type="checkbox" name="confirmed" value="1" title="Check to bypass confirm" style="margin:0 2px;">
                                </label>
                                <button type="submit" name="action_ban" class="btn btn-kill" title="Ban">B</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="section-head" style="margin-top:10px;">
            <span>GUEST UPLINKS (<?= count($active_guests) ?>)</span>
        </div>
        
        <?php if(empty($active_guests)): ?>
            <div class="row" style="color:#444; font-style:italic; font-size:0.7rem;">No guests online.</div>
        <?php endif; ?>

        <?php foreach($active_guests as $g): ?>
            <div class="row">
                <div class="info-col">
                    <span class="username-link" style="color: <?= htmlspecialchars($g['color_hex']) ?>">
                        <?= htmlspecialchars($g['username']) ?>
                    </span>
                    <div class="status-line"><span class="pulse" style="background:#888; box-shadow:none;"></span> UPLINK ACTIVE</div>
                </div>

                <div class="actions-col">
                    <?php if($my_rank >= 9): ?>
                        <form action="settings.php" method="POST" style="display:inline; margin:0;">
                            <input type="hidden" name="revoke_id" value="<?= $g['id'] ?>">
                            <button type="submit" class="btn btn-kill" onclick="return confirm('KILL CONNECTION?');">KILL</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

</body>
</html>