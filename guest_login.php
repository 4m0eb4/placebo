<?php
session_start();
require 'db_config.php';
require 'captcha_config.php';

$error = '';

// STATE MANAGEMENT: 'RULES' -> 'INIT' (Enter Code) -> 'VERIFY' (Captcha)
// Default to RULES if no step is set
$step = $_SESSION['guest_step'] ?? 'RULES';

// RESET logic
if (isset($_GET['reset'])) {
    unset($_SESSION['guest_step'], $_SESSION['pending_token'], $_SESSION['pending_nick']);
    header("Location: guest_login.php"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- STEP 0: AGREE TO RULES ---
    if (isset($_POST['action_agree'])) {
        $_SESSION['guest_step'] = 'INIT';
        header("Location: guest_login.php"); exit;
    }

    // --- STEP 1: VALIDATE TOKEN ---
    if (isset($_POST['action_init'])) {
        $token_val = trim($_POST['token']);
        $nick_val = trim($_POST['nickname']);

        // Check DB for Valid, Pending Token
        $stmt = $pdo->prepare("SELECT * FROM guest_tokens WHERE token = ? AND status = 'pending'");
        $stmt->execute([$token_val]);
        $invite = $stmt->fetch();

        if ($invite) {
            // Check Expiry (If it expired before even being used)
            if ($invite['expires_at'] && strtotime($invite['expires_at']) < time()) {
                $error = "TOKEN EXPIRED.";
            } else {
                // Success -> Move to Captcha
                $_SESSION['pending_token'] = $invite;
                $_SESSION['pending_nick'] = $nick_val;
                $_SESSION['guest_step'] = 'VERIFY';
                
                // Init Captcha (Deterministic)
                $_SESSION['captcha_time'] = time();
                
                // GENERATE GRID
                $gen = generate_deterministic_grid($palette, $gridW, $gridH, $min_sum, $max_sum, $active_min, $active_max);
                $_SESSION['captcha_grid'] = $gen['grid'];
                $_SESSION['captcha_req']  = $gen['req'];
                
                header("Location: guest_login.php"); exit;
            }
        } else {
            $error = "INVALID OR USED TOKEN.";
        }
    }

    // --- STEP 2: VERIFY CAPTCHA ---
    if (isset($_POST['action_verify'])) {
        if ((time() - $_SESSION['captcha_time']) > $timer_login) {
            $error = "TIMEOUT. PATTERN EXPIRED.";
            // Don't reset step, just regen captcha
            $_SESSION['captcha_time'] = time();
            $gen = generate_deterministic_grid($palette, $gridW, $gridH, $min_sum, $max_sum, $active_min, $active_max);
            $_SESSION['captcha_grid'] = $gen['grid'];
            $_SESSION['captcha_req']  = $gen['req'];
        } else {
            // DETERMINISTIC CHECK
            $sel = $_POST['cells'] ?? [];
            $stored_grid = $_SESSION['captcha_grid'] ?? [];
            
            $tally = []; 
            $fail = false; 
            $req = $_SESSION['captcha_req'];

            // 1. Tally User Selection against Stored Grid
            foreach ($sel as $p) {
                $x = explode('-', $p); // "row-col"
                $r = (int)$x[0]; 
                $c = (int)$x[1];
                
                if(isset($stored_grid[$r][$c])) {
                    $actual_color = $stored_grid[$r][$c];
                    
                    // Fail if color not in requirements
                    if (!isset($req[$actual_color])) { 
                        $fail = true; 
                    } else {
                        if (!isset($tally[$actual_color])) $tally[$actual_color] = 0; 
                        $tally[$actual_color]++;
                    }
                }
            }

            // 2. Check if counts match exactly
            if (!$fail) {
                foreach ($req as $color => $needed) {
                    if (($tally[$color] ?? 0) !== $needed) $fail = true;
                }
            }

            if ($fail) {
                $error = "PATTERN FAILED.";
                $_SESSION['captcha_time'] = time();
                // Regen Grid
                $gen = generate_deterministic_grid($palette, $gridW, $gridH, $min_sum, $max_sum, $active_min, $active_max);
                $_SESSION['captcha_grid'] = $gen['grid'];
                $_SESSION['captcha_req']  = $gen['req'];
            } else {
                // --- SUCCESS: ACTIVATE SESSION ---
                $invite = $_SESSION['pending_token'];
                $nick_val = $_SESSION['pending_nick'];

                // 1. Get Creator Info
                $stmt_u = $pdo->prepare("SELECT pgp_fingerprint FROM users WHERE id = ?");
                $stmt_u->execute([$invite['created_by']]);
                $creator_fp = $stmt_u->fetchColumn();
                $snippet = $creator_fp ? substr(str_replace(' ', '', $creator_fp), -4) : 'VOID';

                // 2. Format Nickname
                $clean_nick = preg_replace("/[^a-zA-Z0-9]/", "", $nick_val);
                if(strlen($clean_nick) > 12) $clean_nick = substr($clean_nick, 0, 12);
                if(!$clean_nick) $clean_nick = "Guest";
                $guest_username = $clean_nick . "_" . $snippet . "_" . bin2hex(random_bytes(2));

                // 3. Start Session
                session_regenerate_id(true);
                $_SESSION['fully_authenticated'] = true;
                $_SESSION['is_guest'] = true;
                $_SESSION['user_id'] = 0;
                $_SESSION['username'] = $guest_username;
                $_SESSION['rank'] = 0;
                $_SESSION['guest_token_id'] = $invite['id']; // Bind Token to Session

                // 4. Update Token Status AND Save Username
                $pdo->prepare("UPDATE guest_tokens SET status = 'active', guest_session_id = ?, guest_username = ? WHERE id = ?")
                    ->execute([session_id(), $guest_username, $invite['id']]);

                // Cleanup
                unset($_SESSION['guest_step'], $_SESSION['pending_token'], $_SESSION['pending_nick']);

                header("Location: chat.php"); exit;
            }
        }
    }
}
$current_req = $_SESSION['captcha_req'] ?? [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Guest Uplink</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grid-wrapper, .grid-wrapper img, .grid-overlay { width: <?= $totalW ?>px; height: <?= $totalH ?>px; }
        .grid-overlay { grid-template-columns: repeat(<?= $gridW ?>, <?= $cellSize ?>px); grid-template-rows: repeat(<?= $gridH ?>, <?= $cellSize ?>px); }
        .grid-cell label { width: <?= $cellSize ?>px; height: <?= $cellSize ?>px; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper">
    <div class="terminal-header"><span class="term-title">Guest Acess</span></div>
    <?php if($error): ?><div class="terminal-alert">! <?= $error ?></div><?php endif; ?>
    
    <?php if ($step === 'RULES'): ?>
        <div style="padding: 25px;">
            <h2 style="color: #e06c75; margin-top: 0; font-size: 1rem; border-bottom: 1px solid #333; padding-bottom: 10px;">Rules & About</h2>
            <div style="font-size: 0.8rem; color: #aaa; line-height: 1.5; margin-bottom: 20px;">
                <p>- To be confirmed shortly -</p>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin-bottom: 8px;"><span style="color: #6a9c6a;">>></span>1.No CSAM</li>
                    <li style="margin-bottom: 8px;"><span style="color: #6a9c6a;">>></span>2.</li>
                    <li style="margin-bottom: 8px;"><span style="color: #6a9c6a;">>></span>3.</li>
                    <li style="margin-bottom: 8px;"><span style="color: #6a9c6a;">>></span>4.</li>
                </ul>
                <p style="color: #666; font-style: italic;">Violation will result in immediate termination of the guest token.</p>
            </div>
            
            <form method="POST">
                <button type="submit" name="action_agree" class="btn-primary" style="border-color: #e06c75; color: #e06c75;">
                    I ACKNOWLEDGE & AGREE
                </button>
            </form>
            <a href="login.php" class="link-secondary">&lt; ABORT</a>
        </div>

    <?php elseif ($step === 'INIT'): ?>
        <form method="POST" class="challenge-form">
            <div class="input-group">
                <label>Access Token</label>
                <input type="text" name="token" required autocomplete="off" placeholder="XXXXXXXX" autofocus>
            </div>
            <div class="input-group">
                <label>Username</label>
                <input type="text" name="nickname" required autocomplete="off" placeholder="------------">
            </div>
            <button type="submit" name="action_init" class="btn-primary">Submit</button>
            <a href="login.php" class="link-secondary">&lt; RETURN TO LOGIN</a>
        </form>

    <?php else: ?>
        <form method="POST" class="challenge-form">
            <div class="challenge-meta">
                <div>Uplink: <?= htmlspecialchars($_SESSION['pending_nick']) ?></div>
                <a href="?reset=1" style="color:#e06c75; text-decoration:none;">[ CANCEL ]</a>
            </div>

            <div class="timer-container"><div class="timer-bar" style="animation-duration: <?=$timer_login?>s;"></div></div>
            
            <div class="pattern-list">
                <?php foreach($current_req as $c => $r): ?>
                    <span class="pattern-item" style="border-color:<?=$c?>; color:<?=$c?>;"><?=$c?>:<?=$r?></span>
                <?php endforeach; ?>
            </div>

            <div class="grid-outer">
                <div class="grid-wrapper">
                    <img src="captcha.php?v=<?= time() ?>">
                    <div class="grid-overlay">
                        <?php 
                        for($i=0; $i < $totalCells; $i++): 
                            $r = floor($i / $gridW); $c = $i % $gridW; 
                        ?>
                        <div class="grid-cell"><input type="checkbox" id="c_<?=$i?>" name="cells[]" value="<?=$r?>-<?=$c?>"><label for="c_<?=$i?>"></label></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <button type="submit" name="action_verify" class="btn-verify">ESTABLISH CONNECTION</button>
        </form>
    <?php endif; ?>
</div></body></html>