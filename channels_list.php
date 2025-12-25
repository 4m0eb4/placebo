<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) exit;

$my_rank = $_SESSION['rank'] ?? 0;
$active_id = $_SESSION['active_channel'] ?? 1;

try {
    // 1. Fetch Channels
    $stmt = $pdo->prepare("SELECT * FROM chat_channels WHERE read_rank <= ? ORDER BY id ASC");
    $stmt->execute([$my_rank]);
    $channels = $stmt->fetchAll();

    // 2. Fetch Active Signals (Last 10 minutes)
    // Returns list of channel_ids that have recent messages
    $act_stmt = $pdo->query("SELECT DISTINCT channel_id FROM chat_messages WHERE created_at > (NOW() - INTERVAL 10 MINUTE)");
    $active_chans = $act_stmt->fetchAll(PDO::FETCH_COLUMN);

} catch(Exception $e) { die("DB Error"); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="30">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #0d0d0d; padding: 15px; font-family: monospace; }
        .chan-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px; border: 1px solid #333; margin-bottom: 8px;
            background: #111; text-decoration: none; color: #ccc;
            transition: all 0.2s; position: relative; overflow: hidden;
        }
        .chan-row:hover { border-color: #56b6c2; background: #161616; }
        .chan-row.active { border-color: #6a9c6a; border-left: 4px solid #6a9c6a; }
        .chan-row.signal-glow { box-shadow: 0 0 8px rgba(86, 182, 194, 0.3); border-color: #56b6c2; }
        .chan-name { font-weight: bold; color: #e5c07b; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; }
        .chan-meta { font-size: 0.7rem; color: #666; margin-top: 4px; }
        
        /* Indicators */
        .icon-lock { color: #e06c75; font-size: 0.8rem; }
        .signal-dot { 
            width: 8px; height: 8px; background: #56b6c2; border-radius: 50%; 
            display: inline-block; box-shadow: 0 0 5px #56b6c2;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0% { opacity: 0.4; } 50% { opacity: 1; box-shadow: 0 0 8px #56b6c2; } 100% { opacity: 0.4; } }
    </style>
</head>
<body>
    <?php foreach($channels as $c): ?>
        <?php 
        $has_pass = !empty($c['password']);
        $is_active = in_array($c['id'], $active_chans);
        $row_class = ($c['id'] == $active_id) ? 'active' : '';
        if ($is_active && $c['id'] != $active_id) $row_class .= ' signal-glow';
        ?>
        <a href="chat.php?set_channel=<?= $c['id'] ?>" target="_top" class="chan-row <?= $row_class ?>">
            <div>
                <span class="chan-name">
                    <?php if($c['is_locked']): ?><span style="color:#e06c75;">[LOCKED]</span><?php endif; ?>
                    #<?= htmlspecialchars($c['name']) ?>
                    <?php if($has_pass): ?><span class="icon-lock" title="Encrypted">🔒</span><?php endif; ?>
                </span>
                <div class="chan-meta">
                    <?= ($c['write_rank'] > 0) ? "WRITE: LVL {$c['write_rank']}+" : "PUBLIC WRITE" ?>
                    <?= $has_pass ? " | ENCRYPTED" : "" ?>
                </div>
            </div>
            
            <div style="text-align:right; display:flex; align-items:center; gap:10px;">
                <?php if($is_active): ?>
                    <span style="font-size:0.65rem; color:#56b6c2;">SIGNAL RX</span>
                    <div class="signal-dot"></div>
                <?php endif; ?>
                
                <?php if($c['id'] == $active_id): ?>
                    <span class="badge" style="color:#6a9c6a; border:1px solid #6a9c6a; padding:2px 6px; font-size:0.65rem;">CONNECTED</span>
                <?php else: ?>
                    <span style="color:#56b6c2; font-size:0.8rem;">[ TUNE IN ]</span>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
    
    <?php if(empty($channels)): ?>
        <div style="color:#666; text-align:center; margin-top:50px;">No frequencies detected.</div>
    <?php endif; ?>
</body>
</html>