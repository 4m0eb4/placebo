<?php
session_start();
require 'db_config.php';
require 'captcha_config.php'; 

if (isset($_SESSION['fully_authenticated']) && $_SESSION['fully_authenticated'] === true) {
    header("Location: index.php"); exit;
}

$error = '';
$state = 'LOGIN';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_initiate'])) {
        $username_val = trim($_POST['username']);
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username_val]);
        $user = $stmt->fetch();

        if ($user && password_verify($_POST['password'], $user['password_hash'])) {
            $state = 'CHALLENGE';
            $_SESSION['pending_user'] = $username_val;
            $_SESSION['grid_seed']    = time();
            $_SESSION['captcha_time'] = time();
            // FIX: Added missing arguments $active_min, $active_max
            $_SESSION['captcha_req']  = get_pattern($palette, $min_sum, $max_sum, $active_min, $active_max);
        } else {
            $error = "ACCESS DENIED."; usleep(300000);
        }
    }
    elseif (isset($_POST['action_verify'])) {
        $state = 'CHALLENGE';
        $username_val = $_SESSION['pending_user'] ?? '';
        
        if ((time() - $_SESSION['captcha_time']) > $timer_login) {
            $error = "TIMEOUT."; $state = 'LOGIN'; unset($_SESSION['pending_user']);
        } else {
            // DYNAMIC CHECK
            $sel = $_POST['cells'] ?? [];
            srand($_SESSION['grid_seed']); 
            $map = []; $keys = array_keys($palette);
            
            // Rebuild Map using Config dimensions
            for ($r = 0; $r < $gridH; $r++) {
                for ($c = 0; $c < $gridW; $c++) {
                    $map[$r][$c] = $keys[array_rand($keys)];
                }
            }

            $tally = []; $fail = false; $req = $_SESSION['captcha_req'];
            foreach ($sel as $p) {
                $x = explode('-', $p);
                if(isset($map[(int)$x[0]][(int)$x[1]])) {
                    $col = $map[(int)$x[0]][(int)$x[1]];
                    if (!isset($tally[$col])) $tally[$col] = 0; $tally[$col]++;
                    if (!isset($req[$col])) $fail = true;
                }
            }
            if (!$fail) foreach ($req as $c => $n) if (($tally[$c] ?? 0) !== $n) $fail = true;

            if ($fail) {
                $error = "PATTERN FAILED.";
                $_SESSION['grid_seed'] = time(); $_SESSION['captcha_time'] = time();
                $_SESSION['captcha_req'] = get_pattern($palette, $min_sum, $max_sum, $active_min, $active_max);
            } else {
                // UPDATED: Select 'rank' instead of 'is_admin'
                $stmt = $pdo->prepare("SELECT id, username, pgp_public_key, rank FROM users WHERE username = ?");
                $stmt->execute([$username_val]);
                $user = $stmt->fetch();
                
                // Security: Regenerate ID to prevent Session Fixation
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['pgp_key'] = $user['pgp_public_key'];
                // UPDATED: Store rank in session
                $_SESSION['rank'] = (int)$user['rank'];
                
                unset($_SESSION['pending_user'], $_SESSION['grid_seed']);
                header("Location: challenge.php"); exit;
            }
        }
    }
}
$current_req = $_SESSION['captcha_req'] ?? [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>.placebo.</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grid-wrapper, .grid-wrapper img, .grid-overlay {
            width: <?= $totalW ?>px;
            height: <?= $totalH ?>px;
        }
        .grid-overlay {
            grid-template-columns: repeat(<?= $gridW ?>, <?= $cellSize ?>px);
            grid-template-rows: repeat(<?= $gridH ?>, <?= $cellSize ?>px);
        }
        .grid-cell label {
            width: <?= $cellSize ?>px;
            height: <?= $cellSize ?>px;
        }
        /* CENTERING FIX */
        body {
            display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0;
            background-size: cover; background-position: center;
        }
        .login-wrapper { margin: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.8); }
    </style>
</head>
<body class="<?= $theme_cls ?>" <?= $bg_style ?>>
<div class="login-wrapper">
    <div class="terminal-header">
        <a href="index.php" class="term-logo">.placebo.</a>
        <span class="term-status <?= $state==='CHALLENGE'?'status-warn':'status-ok'?>">
            <?= $state==='CHALLENGE'?'VERIFYING...':('ID: '.strtoupper(substr(session_id(), 0, 8))) ?>
        </span>
    </div>

    <?php if($error): ?><div class="terminal-alert">! <?= $error ?></div><?php endif; ?>
<?php if ($state === 'LOGIN'): ?>
        <form method="POST" class="login-form">
            <div class="input-group"><label>Username</label><input type="text" name="username" value="<?= htmlspecialchars($username_val) ?>" required></div>
            <div class="input-group"><label>Password</label><input type="password" name="password" required></div>
            
            <button type="submit" name="action_initiate" class="btn-primary">Login</button>
            
            <div style="display:flex; justify-content:space-between; margin-top:15px;">
                <a href="register.php" class="link-secondary" style="margin:0;">[ REGISTER ]</a>
                <a href="guest_login.php" class="link-secondary" style="margin:0; color:#e5c07b;">[ GUEST ACCESS ]</a>
            </div>
        </form>
    <?php else: ?>
        <form method="POST" class="challenge-form">
            <div class="challenge-meta">
                <div>User: <?= htmlspecialchars($username_val) ?></div>
            </div>
            <div class="timer-container"><div class="timer-bar" style="animation-duration: <?=$timer_login?>s;"></div></div>
            <div class="timer-expired-label">SESSION EXPIRED</div>

            <div class="pattern-list">
                <?php foreach($current_req as $c => $r): ?>
                    <span class="pattern-item" style="border-color:<?=$c?>; color:<?=$c?>;"><?=$c?>:<?=$r?></span>
                <?php endforeach; ?>
            </div>

            <div class="grid-outer">
                <div class="grid-wrapper">
                    <img src="captcha.php">
                    <div class="grid-overlay">
                        <?php 
                        // DYNAMIC GRID LOOP
                        for($i=0; $i < $totalCells; $i++): 
                            $r = floor($i / $gridW);
                            $c = $i % $gridW; 
                        ?>
                        <div class="grid-cell"><input type="checkbox" id="c_<?=$i?>" name="cells[]" value="<?=$r?>-<?=$c?>"><label for="c_<?=$i?>"></label></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <button type="submit" name="action_verify" class="btn-verify">CONFIRM PATTERN</button>
        </form>
    <?php endif; ?>
    
    <?php
        // ONLINE COUNTER
        $o_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE last_active > (NOW() - INTERVAL 15 MINUTE) AND is_banned = 0");
        $online_count = $o_stmt->fetchColumn();
    ?>
<div class="terminal-footer">
            <?php if(($settings['show_online_nodes'] ?? '1') === '1'): ?>
            <div style="margin-top: 15px; font-size: 0.7rem; color: #444;">
                Active Nodes: <span style="color: #6a9c6a;"><?= $online_count ?></span>
            </div>
            <?php endif; ?>
        </div>
</body></html>