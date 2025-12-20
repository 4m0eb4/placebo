<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'log_proj_db');
define('DB_USER', 'log_proj_u');
define('DB_PASS', 'ChangeMe_To_Something_Secure!'); 

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if (session_status() === PHP_SESSION_NONE) session_start();
    
    // --- 1. INSTANT HEARTBEAT (The Fix) ---
    // This ensures you appear online immediately when navigating the site
    try {
        if (isset($_SESSION['user_id'])) {
            $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
        } elseif (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
            $pdo->prepare("UPDATE guest_tokens SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['guest_token_id']]);
        }
    } catch (Exception $e) {}

    // --- 2. SECURITY & TERMINATION ---
    $force_logout = false;

    // Guest Check
    if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
        if (isset($_SESSION['guest_token_id'])) {
            try {
                $stmt_g = $pdo->prepare("SELECT status, expires_at FROM guest_tokens WHERE id = ?");
                $stmt_g->execute([$_SESSION['guest_token_id']]);
                $g_status = $stmt_g->fetch();

                if (!$g_status || $g_status['status'] !== 'active' || ($g_status['expires_at'] && strtotime($g_status['expires_at']) < time())) {
                    $force_logout = true;
                }
            } catch (Exception $e) { $force_logout = true; } // Kill if DB error on token
        } else {
            $force_logout = true;
        }
    }
    // Registered User Check
    elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        try {
            $stmt_u = $pdo->prepare("SELECT is_banned, force_logout FROM users WHERE id = ?");
            $stmt_u->execute([$_SESSION['user_id']]);
            $u_status = $stmt_u->fetch();

            if ($u_status && ($u_status['is_banned'] == 1 || $u_status['force_logout'] == 1)) {
                $force_logout = true;
                // If it was just a kick (not a ban), reset the flag so they can login again later
                if ($u_status['force_logout'] == 1 && $u_status['is_banned'] == 0) {
                    $pdo->prepare("UPDATE users SET force_logout = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
                }
            }
        } catch (Exception $e) {}
    }

    // --- 3. EXECUTE KILL SWITCH ---
    if ($force_logout) {
        $_SESSION = array(); 
        session_destroy();
        
        // CSS OVERLAY - CLICK ANYWHERE TO RESET FULL WINDOW
        echo "<style>
            html, body { background: #000 !important; width: 100%; height: 100%; margin: 0; overflow: hidden; }
            * { display: none !important; }
            .kill-link { 
                display: flex !important; position: fixed; top: 0; left: 0; 
                width: 100%; height: 100%; background: #000; z-index: 999999;
                align-items: center; justify-content: center; text-decoration: none; cursor: pointer;
            }
            .term-msg { 
                color: #e06c75; font-family: monospace; font-size: 1.5rem; 
                border: 2px solid #e06c75; padding: 20px 40px; background: #1a0505; 
                text-align: center; font-weight: bold; pointer-events: none;
            }
            .sub-msg { color: #666; font-size: 0.8rem; margin-top: 15px; display: block; }
        </style>
        <a href='index.php' target='_top' class='kill-link'>
            <div class='term-msg'>
                CONNECTION TERMINATED
                <span class='sub-msg'>[ CLICK SCREEN TO RESET ]</span>
            </div>
        </a>";
        exit;
    }

    // --- 4. THEME LOADER ---
    $theme_cls = ''; $bg_style = '';
    try {
        $stmt_t = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('site_theme', 'site_bg_url')");
        $t_conf = $stmt_t->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($t_conf['site_theme'])) $theme_cls = $t_conf['site_theme'];
        if (!empty($t_conf['site_bg_url'])) {
            $safe_url = htmlspecialchars($t_conf['site_bg_url']);
            $bg_style = "style='background-image: url(\"$safe_url\");'";
        }
    } catch (Exception $e) { }

} catch (PDOException $e) {
    die("System Error: Database Connection Failed.");
}
?>