<?php
session_start();
require 'db_config.php';
require 'captcha_config.php';

// --- FETCH REGISTRATION STATUS ---
$reg_enabled = true;
$reg_msg = "Registration Suspended.";
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('registration_enabled', 'registration_msg')");
    $s = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if(isset($s['registration_enabled'])) $reg_enabled = ($s['registration_enabled'] === '1');
    if(isset($s['registration_msg'])) $reg_msg = $s['registration_msg'];
} catch (Exception $e) {}

$error = '';
$state = 'FORM'; // 'FORM' or 'CAPTCHA'

// Handle Form Submission
if (isset($_POST['action_check']) || isset($_POST['action_register'])) {
    if (!$reg_enabled) {
        $error = "REGISTRATION IS DISABLED.";
    } else {
        if (isset($_POST['action_check'])) {
            if ($_POST['password'] !== $_POST['password_confirm']) $error = "Passwords do not match.";
            elseif (strlen($_POST['password']) < 12) $error = "Password too short (min 12).";
            elseif (strlen($_POST['username']) > 20) $error = "Username exceeds 20 character limit.";
            // EXPANDED WHITELIST: Alphanumeric + Safe Symbols (No spaces, no HTML chars)
            // Allowed: - _ . ! ? $ * ( ) [ ]
// EXPANDED WHITELIST: Alphanumeric + Safe Symbols
            elseif (!preg_match('/^[a-zA-Z0-9_\-\.\!\?\$\*\(\)\[\]]+$/', $_POST['username'])) {
                $error = "Invalid Username. Allowed: A-Z 0-9 - _ . ! ? $ * ( ) [ ]";
            }
            // BANNED WORDS CHECK
            elseif (preg_match('/(admin|system|root|mod|support|staff|placebo|server|host)/i', $_POST['username'])) {
                $error = "Username contains restricted keywords.";
            }
            else {
                // --- PGP CHECK ---
                $pgp_valid = false;
                $clean_fing = strtoupper(str_replace([' ', '0x'], '', $_POST['fingerprint']));

                if (class_exists('gnupg')) {
                    try {
                        putenv("GNUPGHOME=/tmp");
                        $gpg = new gnupg();
                        $gpg->seterrormode(gnupg::ERROR_EXCEPTION);
                        $info = $gpg->import($_POST['pgp_key']);
                        
                        if (isset($info['fingerprint'])) {
                            $imported_fing = strtoupper($info['fingerprint']);
                            $len = strlen($clean_fing);
                            if ($len > 0 && substr($imported_fing, -$len) === $clean_fing) $pgp_valid = true;
                            else $error = "PGP Mismatch: Key does not match Fingerprint.";
                        } else $error = "Invalid PGP Key.";
                    } catch (Exception $e) { $error = "PGP Error. Check server logs."; }
                } else {
                     if (strpos($_POST['pgp_key'], 'BEGIN PGP PUBLIC KEY BLOCK') !== false) $pgp_valid = true; 
                     else $error = "System Error: php-gnupg missing.";
                }

                if ($pgp_valid && !$error) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$_POST['username']]);
                    if ($stmt->fetch()) $error = "Username taken.";
                    else {
                        $state = 'CAPTCHA';
                        $_SESSION['reg_data'] = $_POST;
                        $_SESSION['grid_seed'] = time();
                        $_SESSION['captcha_time'] = time();
                        // FIX: Ensure 5 arguments are passed here
                        $_SESSION['captcha_req'] = get_pattern($palette, $min_sum, $max_sum, $active_min, $active_max);
                    }
                }
            }
        }

        if (isset($_POST['action_register'])) {
            $state = 'CAPTCHA';
            if ((time() - $_SESSION['captcha_time']) > $timer_register) {
                $error = "Timeout. Please restart.";
                $state = 'FORM';
            } else {
                // --- DYNAMIC GRID CHECK ---
                $sel = $_POST['cells'] ?? [];
                srand($_SESSION['grid_seed']);
                
                $map = []; 
                $keys = array_keys($palette);
                for($r=0; $r<$gridH; $r++) {
                    for($c=0; $c<$gridW; $c++) {
                        $map[$r][$c] = $keys[array_rand($keys)];
                    }
                }
                
                $fail=false; $tally=[]; $req=$_SESSION['captcha_req'];
                foreach($sel as $p) {
                    $x = explode('-',$p);
                    if(isset($map[(int)$x[0]][(int)$x[1]])) {
                        $col = $map[(int)$x[0]][(int)$x[1]];
                        if(!isset($tally[$col])) $tally[$col]=0; $tally[$col]++;
                        if(!isset($req[$col])) $fail=true;
                    }
                }
                if(!$fail) foreach($req as $k=>$v) if(($tally[$k]??0)!==$v) $fail=true;

                if($fail) {
                    $error="Pattern Failed. New Grid.";
                    $_SESSION['grid_seed']=time(); $_SESSION['captcha_time']=time();
                    // FIX: CRITICAL FIX - Added $active_min, $active_max
                    $_SESSION['captcha_req']=get_pattern($palette, $min_sum, $max_sum, $active_min, $active_max);
                } else {
                    $d = $_SESSION['reg_data'];
                    $hash = password_hash($d['password'], PASSWORD_ARGON2ID);
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, pgp_public_key, pgp_fingerprint) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$d['username'], $hash, $d['pgp_key'], $d['fingerprint']]);
                        header("Location: login.php"); 
                        exit;
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) { 
                            $error = "Error: Username already exists."; 
                        } else {
                            $error = "Registration Failed: " . htmlspecialchars($e->getMessage());
                        }
                        $state = 'FORM'; 
                    }
                }
            }
        }
    }
}
$current_req = $_SESSION['captcha_req'] ?? [];
$default_tab_reg = ($error || $state === 'CAPTCHA') ? 'checked' : '';
$default_tab_info = ($default_tab_reg === '') ? 'checked' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
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
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper">
    <div class="terminal-header">
        <a href="login.php" class="term-logo">Placebo</a>
        <span class="term-status">ID: <?= strtoupper(substr(session_id(), 0, 8)) ?></span>
    </div>
    <?php if($error): ?><div class="terminal-alert">! <?= $error ?></div><?php endif; ?>

    <?php if($state==='FORM'): ?>
        <div class="tab-system">
            <input type="radio" id="tab1" name="tabs" <?= $default_tab_info ?>>
            <input type="radio" id="tab2" name="tabs" <?= $default_tab_reg ?>>
            
            <div class="tab-nav">
                <label for="tab1" class="tab-btn small-tab">Info</label>
                <label for="tab2" class="tab-btn big-tab">Create Account</label>
            </div>

            <div class="tab-content content-info">
                <div class="rules-box">
                    <h3>Account Info</h3>
                    <ul>
                        <li><span class="bullet">></span> <strong>Security:</strong> A PGP Key is required for login.</li>
                        <li><span class="bullet">></span> <strong>Privacy:</strong> No Javascript. No IP Logging.</li>
                        <li><span class="bullet">></span> <strong>-:</strong>--</li>
                        <li><span class="bullet">></span> <strong>Rules:</strong>No CSAM</li>
                    </ul>
                    <p class="info-note">By proceeding, you agree to the security standards.</p>
                </div>
            </div>

            <div class="tab-content content-reg">
                <?php if ($reg_enabled): ?>
                    <form method="POST" class="login-form">
                        <div class="input-group"><label>Username</label><input type="text" name="username" required></div>
                        <div class="input-group"><label>Password</label><input type="password" name="password" required></div>
                        <div class="input-group"><label>Confirm Password</label><input type="password" name="password_confirm" required></div>
                        <div class="input-group"><label>PGP Fingerprint</label><input type="text" name="fingerprint" required></div>
                        <div class="input-group"><label>PGP Public Key</label><textarea name="pgp_key" class="pgp-box" required></textarea></div>
                        <button type="submit" name="action_check" class="btn-primary">Verify Data</button>
                    </form>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; border: 1px dashed #e06c75; background: #1a0505; color: #e06c75; margin: 10px;">
                        <h3 style="margin-top: 0; font-size: 1rem; border-bottom: 1px solid #e06c75; padding-bottom: 5px; display:inline-block;">ACCESS RESTRICTED</h3>
                        <p style="white-space: pre-wrap; font-family: monospace; font-size: 0.8rem; line-height: 1.5; overflow-wrap: break-word;"><?= htmlspecialchars($reg_msg) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bottom-nav">
            <a href="index.php" class="back-link">&lt; RETURN TO LOGIN</a>
        </div>
        
    <?php else: ?>
        <form method="POST" class="challenge-form">
            <div class="challenge-meta">
                <div>Status: Verifying</div>
            </div>

            <div class="timer-container">
                <div class="timer-bar" style="animation-duration: <?=$timer_register?>s;"></div>
            </div>
            <div class="pattern-list">
                <?php foreach($current_req as $c=>$r): ?><span class="pattern-item" style="color:<?=$c?>;border-color:<?=$c?>"><?=$c?>:<?=$r?></span><?php endforeach; ?>
            </div>
            
            <div class="grid-outer">
                <div class="grid-wrapper">
                    <img src="captcha.php?v=<?= time() ?>" alt="Security Grid">
                    <div class="grid-overlay">
                        <?php 
                        for($i=0; $i < $totalCells; $i++): 
                            $r = floor($i / $gridW);
                            $c = $i % $gridW; 
                        ?>
                        <div class="grid-cell"><input type="checkbox" id="c_<?=$i?>" name="cells[]" value="<?=$r?>-<?=$c?>"><label for="c_<?=$i?>"></label></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <button type="submit" name="action_register" class="btn-verify">Register</button>
            
             <div class="bottom-nav" style="margin-top: 15px; border-top: 1px solid #222; padding-top: 10px;">
                <a href="index.php" class="back-link">&lt; Cancel & Return</a>
            </div>
        </form>
    <?php endif; ?>
</div></body></html>