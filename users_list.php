<?php
session_start();
require 'db_config.php';

// Auth Check (Dynamic)
$stmt_p = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($stmt_p->fetchColumn() ?: '{}', true);
$req_dir = $perms['perm_view_directory'] ?? 3;

if (!isset($_SESSION['fully_authenticated']) || ($_SESSION['rank'] ?? 0) < $req_dir) {
    die("ACCESS DENIED: CLEARANCE LEVEL $req_dir REQUIRED.");
}

// Search Logic
$search = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT id, username, rank, last_active, user_status, chat_color FROM users WHERE 1=1";

if ($search) {
    $sql .= " AND username LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY username ASC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Directory</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .u-row { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #222; font-size: 0.8rem; }
        .u-row:hover { background: #111; }
        .u-rank { font-family: monospace; color: #666; font-size: 0.7rem; border: 1px solid #333; padding: 2px 5px; border-radius: 3px; }
        .u-status { color: #555; font-size: 0.7rem; font-style: italic; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display:block; padding:20px;">
    
    <div style="max-width:800px; margin:0 auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #333; padding-bottom:10px; margin-bottom:20px;">
            <h2 style="color:#d19a66; margin:0;">USER DIRECTORY</h2>
            <a href="index.php" style="color:#666; text-decoration:none; font-size:0.8rem;">[ RETURN ]</a>
        </div>

        <form method="GET" style="margin-bottom:20px; display:flex; gap:10px;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search Username..." style="background:#0d0d0d; border:1px solid #333; color:#fff; padding:8px; flex-grow:1;">
            <button type="submit" class="btn-primary" style="width:auto; padding:0 15px;">SEARCH</button>
        </form>

        <div style="background:#0f0f0f; border:1px solid #333;">
            <?php if(empty($users)): ?>
                <div style="padding:20px; text-align:center; color:#555;">No signals found.</div>
            <?php else: ?>
                <?php 
        require_once 'bbcode.php'; // Ensure bbcode parser is available
        foreach($users as $u): 
            // STYLE PARSER
            $raw_s = $u['chat_color'] ?? '';
            $raw_s = str_ireplace(['url(', 'http', 'ftp', 'expression'], '', $raw_s); // Security strip
            
            $inner_html = htmlspecialchars($u['username']);
            $wrapper_style = "color:#ccc;"; // Default

            if (strpos($raw_s, '{u}') !== false || (str_starts_with(trim($raw_s), '[') && str_ends_with(trim($raw_s), ']'))) {
                $processed = str_replace('{u}', $u['username'], $raw_s);
                if (strpos($raw_s, '{u}') === false) $processed = $raw_s . $u['username'];
                $inner_html = parse_bbcode($processed);
                $wrapper_style = ""; // Style is in inner_html
            } elseif (strpos($raw_s, ';') !== false || strpos($raw_s, ':') !== false) {
                $wrapper_style = $raw_s;
            } else {
                if(!empty($raw_s)) $wrapper_style = "color: $raw_s";
            }
        ?>
            <div class="u-row">
                <div>
                    <a href="profile.php?id=<?= $u['id'] ?>" target="_blank" style="font-weight:bold; text-decoration:none; margin-right:10px; <?= $wrapper_style ?>">
                        <?= $inner_html ?>
                    </a>
                    <span class="u-rank">LVL <?= $u['rank'] ?></span>
                </div>
                        <div style="text-align:right;">
                             <span style="font-size:0.7rem; color:#444; margin-right:10px; font-family:monospace;">
                                 <?= $u['last_active'] ? date('d/m/y', strtotime($u['last_active'])) : '' ?>
                             </span>
                             <span class="u-status"><?= htmlspecialchars($u['user_status'] ?? '') ?></span>
                             <a href="pm.php?to=<?= $u['id'] ?>" target="_blank" style="margin-left:10px; color:#6a9c6a; text-decoration:none;">[ PM ]</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>