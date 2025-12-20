<?php
session_start();
require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated'])) exit;

$my_id = $_SESSION['user_id'];
$my_rank = $_SESSION['rank'] ?? 0;

// --- 1. INSTANT HEARTBEAT (FORCE ONLINE) ---
try {
    // Force update to ensure 'Me' shows in the list immediately
    $stmt = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?");
    $stmt->execute([$my_id]);
    
    // GUEST HEARTBEAT (If applicable)
    if(isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
        $pdo->prepare("UPDATE guest_tokens SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['guest_token_id']]);
    }
} catch (Exception $e) {
    // If this fails, the DB schema is likely missing 'last_active'.
    // Please run db_master_update.php
}

// --- 2. FETCH USERS ---
$active_users = [];
try {
    // Admin sees everyone; Users see only visible
    $visibility_check = ($my_rank >= 9) ? "1=1" : "(show_online = 1 OR id = $my_id)";
    
    // RELAXED QUERY: Fetch everyone seen in 24 hours.
    // 'is_live' = 1 if seen in the last 5 minutes.
    $stmt = $pdo->prepare("
        SELECT id, username, rank, chat_color, user_status, last_active,
               (CASE WHEN last_active > (NOW() - INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as is_live
        FROM users 
        WHERE last_active > (NOW() - INTERVAL 24 HOUR)
        AND $visibility_check
        AND is_banned = 0
        ORDER BY is_live DESC, last_active DESC
    ");
    $stmt->execute();
    $active_users = $stmt->fetchAll();
} catch (Exception $e) {
    // Debug: If this fails, your DB is missing columns.
    echo "";
}

// --- 3. FETCH GUESTS ---
$active_guests = [];
try {
    // STRICT 5 MINUTE TIMEOUT + GROUP BY (Prevent Duplicates)
    $stmt_g = $pdo->prepare("
        SELECT guest_username as username, '#888888' as color_hex
        FROM guest_tokens 
        WHERE last_active > (NOW() - INTERVAL 5 MINUTE)
        AND status = 'active'
        GROUP BY guest_username
        ORDER BY guest_username ASC
    ");
    $stmt_g->execute();
    $active_guests = $stmt_g->fetchAll();
    
    // Get colors
    foreach($active_guests as &$g) {
        $c_stmt = $pdo->prepare("SELECT color_hex FROM chat_messages WHERE username = ? ORDER BY id DESC LIMIT 1");
        $c_stmt->execute([$g['username']]);
        $c = $c_stmt->fetchColumn();
        if($c) $g['color_hex'] = $c;
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html>
<head>
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

        /* HEADER */
        .top-bar {
            background: #161616;
            border-bottom: 1px solid #333;
            padding: 10px;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            position: sticky; top: 0;
            z-index: 100;
        }
        .monitor-label { font-size: 0.75rem; color: #6a9c6a; font-weight: bold; letter-spacing: 1px; }
        .refresh-btn {
            background: #000; border: 1px solid #333; color: #888;
            padding: 4px 10px; font-size: 0.65rem; text-decoration: none;
            cursor: pointer;
        }
        .refresh-btn:hover { color: #fff; border-color: #666; }

        /* LIST CONTAINER */
        .list-container { padding: 0; margin: 0; }

        /* SECTION HEADERS */
        .section-head {
            background: #111;
            color: #555;
            font-size: 0.65rem;
            padding: 6px 12px;
            border-bottom: 1px solid #222;
            text-transform: uppercase;
        }

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
        .info-col { display: flex; flex-direction: column; gap: 2px; }
        
        .username-link { font-size: 0.8rem; font-weight: bold; text-decoration: none; }
        
        .status-line { font-size: 0.65rem; color: #555; display: flex; align-items: center; gap: 5px; }
        
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
        .rank-10 { border-color: #d19a66; color: #d19a66; }
        .rank-9 { border-color: #6a9c6a; color: #6a9c6a; }

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

    <div class="top-bar">
        <span class="monitor-label">ACTIVE SIGNALS</span>
        <a href="users_online.php" class="refresh-btn">REFRESH</a>
    </div>

    <div class="list-container">
        
        <div class="section-head">REGISTERED NODES (<?= count($active_users) ?>)</div>
        
        <?php if(empty($active_users)): ?>
            <div class="row" style="color:#444; font-style:italic; font-size:0.7rem;">No signals detected.</div>
        <?php endif; ?>

       <?php foreach($active_users as $u): ?>
            <?php $opacity = $u['is_live'] ? '1.0' : '0.4'; ?>
            <div class="row" style="opacity: <?= $opacity ?>;">
                <div class="info-col">
                    <div>
                        <a href="profile.php?id=<?= $u['id'] ?>" target="_blank" class="username-link" style="color: <?= htmlspecialchars($u['chat_color']) ?>">
                            <?= htmlspecialchars($u['username']) ?>
                        </a>
                        <?php if(!$u['is_live']): ?><span style="font-size:0.6rem; color:#666;">[IDLE]</span><?php endif; ?>
                        <span class="badge rank-<?= $u['rank'] ?>">L<?= $u['rank'] ?></span>
                    </div>
                    <?php if(!empty($u['user_status'])): ?>
                        <div class="status-line"><span class="pulse"></span> <?= htmlspecialchars($u['user_status']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="actions-col">
                    <?php if($u['id'] != $my_id): ?>
                        <a href="pm.php?to=<?= $u['id'] ?>" target="_blank" class="btn">PM</a>
                    <?php endif; ?>

                    <?php if($my_rank >= 9 && $u['rank'] < 9): ?>
                        <form action="admin_exec.php" method="POST" style="display:inline; margin:0;">
                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                            <button type="submit" name="action_kick" class="btn btn-kill" title="Kick">K</button>
                            <button type="submit" name="action_ban" class="btn btn-kill" title="Ban" onclick="return confirm('BAN USER?');">B</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="section-head">GUEST UPLINKS (<?= count($active_guests) ?>)</div>
        
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
                        <form action="admin_exec.php" method="POST" style="display:inline; margin:0;">
                            <input type="hidden" name="target_guest_name" value="<?= htmlspecialchars($g['username']) ?>">
                            <button type="submit" class="btn btn-kill" onclick="return confirm('KILL CONNECTION?');">KILL</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

</body>
</html>