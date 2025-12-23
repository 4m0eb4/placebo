<?php
session_start();

if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}


require 'db_config.php';
require 'bbcode.php';

// Access Control
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// Fetch Target User
$id = $_GET['id'] ?? $_SESSION['user_id'];
// ADDED: chat_color, mute/slow status
$stmt = $pdo->prepare("SELECT username, rank, chat_color, created_at, pgp_public_key, pgp_fingerprint, is_muted, slow_mode_override FROM users WHERE id = ?");
$stmt->execute([$id]);
$profile = $stmt->fetch();

if (!$profile) die("<html><body style='background:#000;color:#888;font-family:monospace;text-align:center;padding:50px;'>USER_NOT_FOUND</body></html>");

// Rank Colors
// Rank Colors - FULL CONTROL (1-10)
$rank_color = match((int)$profile['rank']) {
    10 => '#d19a66', // OWNER (Gold)
    9  => '#6a9c6a', // HEAD ADMIN (Green)
    8  => '#56b6c2', // ADMIN (Cyan)
    7  => '#c678dd', // SR MOD (Purple)
    6  => '#c678dd', // MOD (Purple)
    5  => '#e5c07b', // VIP (Yellow)
    4  => '#61afef', // TRUSTED (Blue)
    3  => '#98c379', // MEMBER (Light Green)
    2  => '#abb2bf', // VERIFIED (White)
    default => '#5c6370' // PEASANT (Dark Grey)
};

?>
<!DOCTYPE html>
<html>
<head>
    <title>Profile // <?= htmlspecialchars($profile['username']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-card { background: var(--panel-bg); border: 1px solid var(--border-color); width: 600px; margin: 50px auto; padding: 30px; }
        .p-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
        .p-username { font-size: 1.5rem; color: #fff; font-weight: bold; }
        .p-rank { font-family: monospace; padding: 4px 8px; border: 1px solid <?= $rank_color ?>; color: <?= $rank_color ?>; font-size: 0.8rem; }
        .p-stat { margin-bottom: 10px; font-size: 0.8rem; color: #666; }
        .p-stat span { color: #aaa; margin-left: 10px; font-family: monospace; }
        .pgp-block { background: #080808; border: 1px solid #333; padding: 15px; font-size: 0.7rem; color: #6a9c6a; overflow-x: auto; white-space: pre-wrap; font-family: monospace; margin-top: 10px; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display:block;">

<div class="main-container" style="width: 800px; margin: 0 auto;">
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px;">
        <a href="index.php" class="term-title" style="text-decoration:none; color:#888;">
            &lt; RETURN TO FEED
        </a>
    </div>

    <div class="content-area" style="padding: 30px; background: #0d0d0d; min-height: 80vh;">
        <div class="profile-card" style="background: var(--panel-bg); border: 1px solid var(--border-color); width: 100%; max-width: 600px; margin: 20px auto; padding: 30px; box-sizing: border-box;">
            
            <div class="p-header">
                <span class="p-username">
                <?php
                    // RENDER USERNAME STYLE
                    $raw_style = $profile['chat_color'] ?? '';
                    $raw_style = str_ireplace(['url(', 'http', 'ftp', 'expression'], '', $raw_style);
                    $display_name = $profile['username']; // Raw name

                    // Check if Template (contains {u} or brackets)
                    if (strpos($raw_style, '{u}') !== false || (str_starts_with(trim($raw_style), '[') && str_ends_with(trim($raw_style), ']'))) {
                        $processed = str_replace('{u}', $display_name, $raw_style);
                        if (strpos($raw_style, '{u}') === false) $processed = $raw_style . $display_name;
                        echo parse_bbcode($processed);
                    } else {
                        // CSS or Hex
                        $css = (strpos($raw_style, ':') !== false || strpos($raw_style, ';') !== false) ? $raw_style : "color: $raw_style";
                        echo "<span style=\"$css\">" . htmlspecialchars($display_name) . "</span>";
                    }
                ?>
                </span>
                <span class="p-rank">LEVEL <?= $profile['rank'] ?></span>
            </div>

            <div class="p-stat">IDENTITY ID: <span>#<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></span></div>
            <div class="p-stat">REGISTERED: <span><?= $profile['created_at'] ?></span></div>
            <div class="p-stat">FINGERPRINT: <span style="color: #e06c75;"><?= htmlspecialchars($profile['pgp_fingerprint']) ?></span></div>

            <div style="margin-top: 25px;">
                <div style="color: #555; font-size: 0.75rem; margin-bottom: 5px;">PGP PUBLIC KEY BLOCK</div>
                <div class="pgp-block"><?= htmlspecialchars($profile['pgp_public_key']) ?></div>
            </div>
            
<?php if($id == $_SESSION['user_id']): ?>
                <div style="margin-top: 20px; text-align: right;">
                    <a href="settings.php" class="btn-primary" style="display:inline-block; width:auto; font-size:0.7rem; padding: 8px 15px; text-decoration:none;">EDIT IDENTITY</a>
                </div>
            <?php endif; ?>

            <?php 
                // FETCH PERMS FOR BUTTON VISIBILITY
                $perm_ban = 9; $perm_nuke = 10;
                try {
                    $s = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='permissions_config'")->fetchColumn();
                    $j = json_decode($s, true);
                    if($j) { $perm_ban = $j['perm_user_ban']; $perm_nuke = $j['perm_user_nuke']; }
                } catch(Exception $e){}
                
                $my_r = $_SESSION['rank'] ?? 0;
            ?>

            <?php if($my_r >= 5 && $id != $_SESSION['user_id']): ?>
                <div style="margin-top: 40px; border-top: 1px dashed #e06c75; padding-top: 20px;">
                    <h4 style="color: #e06c75; margin-top: 0;">ADMIN CONTROL</h4>
                    <form method="POST" action="admin_exec.php" style="display:flex; flex-wrap:wrap; gap: 10px;">
                        <input type="hidden" name="target_id" value="<?= $id ?>">
                        <input type="hidden" name="return_to" value="profile.php?id=<?= $id ?>">

                        <?php if($profile['is_muted']): ?>
                            <button type="submit" name="action_unmute" class="btn-primary" style="width:auto; background:#1a1a05; color:#e5c07b; border-color:#e5c07b;">[ UNMUTE ]</button>
                        <?php else: ?>
                            <button type="submit" name="action_mute" class="btn-primary" style="width:auto; background:#1a0505; color:#e5c07b; border-color:#e5c07b;">[ MUTE ]</button>
                        <?php endif; ?>

                        <?php if($profile['slow_mode_override'] > 0): ?>
                            <button type="submit" name="action_unslow" class="btn-primary" style="width:auto; background:#0f1a1a; color:#56b6c2; border-color:#56b6c2;">[ UNSLOW ]</button>
                        <?php else: ?>
                            <button type="submit" name="action_slow" class="btn-primary" style="width:auto; background:#05101a; color:#56b6c2; border-color:#56b6c2;">[ SLOW 15s ]</button>
                        <?php endif; ?>
                        
                        <?php if($my_rank >= $perm_ban): ?>
                        <button type="submit" name="action_ban" class="btn-primary" style="width:auto; background: #3e1b1b; color: #e06c75; border-color: #e06c75;" onclick="return confirm('CONFIRM BAN?');">[ BAN ]</button>
                        <?php endif; ?>

                        <?php if($my_rank >= $perm_nuke): ?>
                        <button type="submit" name="action_nuke" class="btn-primary" style="width:auto; background: #000; color: #666; border-color: #444;" onclick="return confirm('PERMANENTLY DELETE ACCOUNT?');">[ NUKE ]</button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</body>
</html>