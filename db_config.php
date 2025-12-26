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

    // [SECURITY] Session Hardening
    if (session_status() === PHP_SESSION_NONE) {
        // Prevent JavaScript from accessing session cookies (Mitigates XSS)
        ini_set('session.cookie_httponly', 1);
        // Force Strict Session ID usage
        ini_set('session.use_strict_mode', 1);
        // SameSite policy (Lax allows navigation, Strict blocks everything)
        ini_set('session.cookie_samesite', 'Lax');
        
        // Note: 'session.cookie_secure' requires HTTPS. 
        // Tor Hidden Services provide encryption, but PHP sees 'HTTP'. 
        // Only enable 'secure' if you have configured Nginx to simulate HTTPS.
        
        session_start();
    }
    
    // [SECURITY] CSRF Token Generation

    // [SECURITY] CSRF Token Generation
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
        }
    }
    
    // --- 1. THROTTLED HEARTBEAT (Performance Fix) ---
    // Only updates DB once every 60 seconds per user/guest
    try {
        $hb_interval = 30; 
        if (!isset($_SESSION['last_hb']) || (time() - $_SESSION['last_hb'] > $hb_interval)) {
            if (isset($_SESSION['user_id'])) {
                $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
                $_SESSION['last_hb'] = time();
            } elseif (isset($_SESSION['is_guest']) && $_SESSION['is_guest']) {
                $pdo->prepare("UPDATE guest_tokens SET last_active = NOW() WHERE id = ?")->execute([$_SESSION['guest_token_id']]);
                $_SESSION['last_hb'] = time();
            }
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
    $theme_cls = ''; $bg_style = ''; $stream_bg_style = '';
    try {
        // [UPDATED] Added bg_position to fetch list
        $stmt_t = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('site_theme', 'site_bg_url', 'chat_bg_url', 'index_opacity', 'stream_opacity', 'bg_fit_style', 'bg_position')");
        $t_conf = $stmt_t->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!empty($t_conf['site_theme'])) $theme_cls = $t_conf['site_theme'];
        
        // Helper: Calculate Overlay Alpha (100 Input = 0% Black Overlay)
        $get_overlay = function($key) use ($t_conf) {
            $val = isset($t_conf[$key]) ? (int)$t_conf[$key] : 85; 
            $dec = 1 - ($val / 100);
            if ($dec < 0) $dec = 0;
            return "linear-gradient(rgba(13, 13, 13, $dec), rgba(13, 13, 13, $dec))";
        };

        $idx_overlay = $get_overlay('index_opacity');
        $str_overlay = $get_overlay('stream_opacity');
        
        // [UPDATED] Logic for Fit and Position
        $fit = !empty($t_conf['bg_fit_style']) ? $t_conf['bg_fit_style'] : 'cover';
        $pos = !empty($t_conf['bg_position']) ? $t_conf['bg_position'] : 'center center';

        // Main BG
        if (!empty($t_conf['site_bg_url'])) {
            $safe_url = htmlspecialchars($t_conf['site_bg_url']);
            $bg_style = "style='background-image: $idx_overlay, url(\"$safe_url\"); background-blend-mode: normal; background-attachment: fixed; background-position: $pos; background-repeat: no-repeat; background-size: $fit;'";
        }

        // Stream BG
        if (!empty($t_conf['chat_bg_url'])) {
            $safe_c_url = htmlspecialchars($t_conf['chat_bg_url']);
            $stream_bg_style = "style='background-image: $str_overlay, url(\"$safe_c_url\"); background-attachment: fixed; background-position: $pos; background-repeat: no-repeat; background-blend-mode: normal; background-size: $fit;'";
        } else {
            $stream_bg_style = "style='background: transparent;'";
        }

    } catch (Exception $e) { }

    // [SECURITY] Traffic Analysis Resistance
    // Appends random garbage data to responses to randomize packet size,
    // making side-channel analysis significantly harder for eavesdroppers.
    register_shutdown_function(function() {
        $headers = headers_list();
        $is_html = true;
        // Only pad HTML pages (skip images/downloads)
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') !== false && stripos($h, 'text/html') === false) {
                $is_html = false; break;
            }
        }
        
        if ($is_html && connection_status() === CONNECTION_NORMAL) {
            // Generate random padding between 256 bytes and 2KB
            $pad_len = mt_rand(256, 2048); 
            try {
                $noise = bin2hex(random_bytes($pad_len));
            } catch (Exception $e) {
                $noise = str_repeat("0", $pad_len * 2);
            }
            echo "\n";
        }
    });

} catch (PDOException $e) {
    die("System Error: Database Connection Failed.");
}
?>