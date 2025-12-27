<?php
// chat_status.php - Alternating Heartbeat (False-Positive Fix)
set_time_limit(0);
ignore_user_abort(false); 
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();

header('X-Accel-Buffering: no'); // Vital for Nginx
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');

$start_time = time();
$recycle_limit = 900; // 15 Minutes
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: transparent; }
        body { 
            font-family: monospace; 
            display: flex; align-items: center; justify-content: flex-end; 
        }

        .signal-box {
            font-weight: bold; font-size: 0.7rem;
            padding: 4px 10px; border: 1px solid transparent;
            cursor: pointer; position: relative;
            text-decoration: none; white-space: nowrap;
        }

        /* --- DEFINITION A (Green -> Red) --- */
        @keyframes color_A {
            0%   { color: #5a8a5a; border-color: transparent; background: transparent; box-shadow: none; }
            95%  { color: #5a8a5a; border-color: transparent; background: transparent; box-shadow: none; }
            100% { color: #e06c75; border-color: #e06c75; background: #1a0505; box-shadow: 0 0 10px #e06c75; }
        }
        @keyframes hide_ok_A { 0% { opacity: 1; } 95% { opacity: 1; } 100% { opacity: 0; } }
        @keyframes show_err_A { 0% { opacity: 0; } 95% { opacity: 0; } 100% { opacity: 1; } }

        /* --- DEFINITION B (Identical Copy, Different Name) --- */
        @keyframes color_B {
            0%   { color: #5a8a5a; border-color: transparent; background: transparent; box-shadow: none; }
            95%  { color: #5a8a5a; border-color: transparent; background: transparent; box-shadow: none; }
            100% { color: #e06c75; border-color: #e06c75; background: #1a0505; box-shadow: 0 0 10px #e06c75; }
        }
        @keyframes hide_ok_B { 0% { opacity: 1; } 95% { opacity: 1; } 100% { opacity: 0; } }
        @keyframes show_err_B { 0% { opacity: 0; } 95% { opacity: 0; } 100% { opacity: 1; } }

        /* Defaults */
        .txt-ok { opacity: 1; }
        .txt-err { opacity: 0; position: absolute; right: 10px; top: 4px; color: inherit; }

    </style>
</head>
<body>

    <a href="chat.php" target="_top" class="signal-box" id="mon">
        <span style="visibility:hidden;">[ SIGNAL LOST - MANUAL REFRESH REQUIRED ]</span>
        <span class="txt-err" id="t_err">[ SIGNAL LOST - MANUAL REFRESH REQUIRED ]</span>
        <span class="txt-ok" id="t_ok" style="position:absolute; right:10px; top:4px;">[ SIGNAL: OKAY ]</span>
    </a>

<?php
echo str_repeat(" ", 1024); // Flush Headers
flush();

// --- HEARTBEAT LOOP ---
$toggle = true;

while (true) {
    if ((time() - $start_time) > $recycle_limit) break; // End normally
    if (connection_aborted()) break; // End if user leaves

    // ALTERNATE between Animation Set A and Set B
    // This forces the browser to treat it as a NEW animation every time, resetting the clock to 0.
    $s = $toggle ? 'A' : 'B';
    $toggle = !$toggle;

    // Apply the new animation set (45s Tolerance)
    echo "<style>
        #mon { animation: color_$s 45s linear forwards !important; }
        #t_ok { animation: hide_ok_$s 45s linear forwards !important; }
        #t_err { animation: show_err_$s 45s linear forwards !important; }
    </style>";

    // Padding forces Nginx/Tor to deliver the packet
    echo "\n"; 
    flush();

    sleep(5);
}
?>
</body>
</html>