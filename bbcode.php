<?php
// bbcode.php - Complete (Standard + Myriad FX)

function format_pgp_armor($input) {
    $s = trim($input);

    // Only touch PGP MESSAGE armor
    if (stripos($s, '-----BEGIN PGP MESSAGE-----') === false || stripos($s, '-----END PGP MESSAGE-----') === false) {
        return $input;
    }

    // Normalize line endings
    $s = str_replace(["\r\n", "\r"], "\n", $s);

    // Extract content even if BEGIN/END are on the SAME LINE
    if (!preg_match('/-----BEGIN PGP MESSAGE-----([\s\S]*?)-----END PGP MESSAGE-----/i', $s, $m)) {
        return $input;
    }

    $inside = trim($m[1]);

    // Split by ANY whitespace (spaces/newlines) so one-line and multi-line both work
    $parts = preg_split('/\s+/', $inside, -1, PREG_SPLIT_NO_EMPTY);

    $payload = '';
    $checksum = '';

    foreach ($parts as $p) {
        // Checksum line (e.g. =Uk3M)
        if ($p !== '' && $p[0] === '=') {
            $checksum = preg_replace('/[^A-Za-z0-9+\/=]/', '', $p);
            continue;
        }

        // Kill the junk your pipeline keeps injecting
        // (covers: PGPMESSAGE, ***PGPMESSAGE-, etc)
        if (stripos($p, 'PGP') !== false || stripos($p, 'MESSAGE') !== false) {
            continue;
        }

        // Keep only base64 characters
        $p = preg_replace('/[^A-Za-z0-9+\/=]/', '', $p);
        if ($p === '') continue;

        $payload .= $p;
    }

    // Final cleanup
    $payload = preg_replace('/[^A-Za-z0-9+\/=]/', '', $payload);
    if ($payload === '') {
        return $input;
    }

    // Rebuild correct armor with REQUIRED blank line
    $out = [];
    $out[] = '-----BEGIN PGP MESSAGE-----';
    $out[] = '';

    foreach (str_split($payload, 64) as $line) {
        if ($line !== '') $out[] = $line;
    }

    if ($checksum !== '') {
        $out[] = $checksum;
    }

    $out[] = '-----END PGP MESSAGE-----';

    return implode("\n", $out) . "\n";
}



function get_bbcode_menu() {
    return '
    <div class="bbcode-wrapper">
        <input type="checkbox" id="bbcode-toggle" class="toggle-box">
        <label for="bbcode-toggle" class="btn-primary" style="display:inline-block; width:auto; padding:5px 15px; margin-bottom:10px; font-size:0.7rem;">
            [ OPEN TAG MENU ]
        </label>
        
        <div class="bbcode-drawer">
            <div class="bb-grid">
                <div class="bb-col">
                    <span class="bb-head">FORMAT</span>
                    <code>[b]Bold[/b]</code>
                    <code>[i]Italic[/i]</code>
                    <code>[u]Underline[/u]</code>
                    <code>[s]Strike[/s]</code>
                    <code>[h1]Head[/h1]</code>
                </div>
                <div class="bb-col">
                    <span class="bb-head">LAYOUT</span>
                    <code>[center]Txt[/center]</code>
                    <code>[quote]Txt[/quote]</code>
                    <code>[box=Title]...[/box]</code>
                    <code>[list][*]Item[/list]</code>
                    <code>[code]...[/code]</code>
                </div>
                <div class="bb-col">
                    <span class="bb-head">VISUALS</span>
                    <code>[color=red]Txt[/color]</code>
                    <code>[size=1.2]Txt[/size]</code>
                    <code>[glow]Neon[/glow]</code>
                    <code>[blur]Haze[/blur]</code>
                    <code>[mirror]Rev[/mirror]</code>
                </div>
                <div class="bb-col">
                    <span class="bb-head">SECURITY/FX</span>
                    <code>[pgp]Key[/pgp]</code>
                    <code>[redacted]###[/redacted]</code>
                    <code>[spoiler]Hide[/spoiler]</code>
                    <code>[glitch]Hack[/glitch]</code>
                    <code>[shake]Panic[/shake]</code>
                    <code>[rainbow]RGB[/rainbow]</code>
                    <code>[xmas]Message[/xmas]</code>
                </div>
            </div>
        </div>
    </div>
    <style>
        .bbcode-wrapper { margin-bottom: 10px; }
        .toggle-box { display: none; }
        .bbcode-drawer { 
            max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; 
            background: var(--panel-bg); border: 1px solid var(--border-color); border-top: none;
        }
        .toggle-box:checked ~ .bbcode-drawer { max-height: 400px; border-top: 1px solid var(--border-color); }
        .bb-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 15px; }
        .bb-col { display: flex; flex-direction: column; gap: 5px; }
        .bb-head { color: var(--accent-secondary); font-size: 0.7rem; font-weight: bold; border-bottom: 1px solid var(--border-color); margin-bottom: 5px; }
        .bbcode-drawer code { font-family: monospace; color: #888; font-size: 0.7rem; background: #080808; padding: 2px 4px; border: 1px solid #222; }
    </style>
    ';
}

function parse_bbcode($text) {
    global $pdo;
    // --- 0) Extract [pgp] blocks BEFORE htmlspecialchars/nl2br so they remain atomic/copyable
    $pgp_blocks = [];
    $text = preg_replace_callback('/\[pgp\](.*?)\[\/pgp\]/is', function ($m) use (&$pgp_blocks) {
        $token = '__PGP_BLOCK_' . count($pgp_blocks) . '__';
        $pgp_blocks[$token] = $m[1]; // raw inner text
        return $token;
    }, $text);

    // 1) Escape everything else (prevents XSS)
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // 2) Simple Replacements (PGP removed from this stage)
    $find = [
        '/\[b\](.*?)\[\/b\]/s',
        '/\[i\](.*?)\[\/i\]/s',
        '/\[u\](.*?)\[\/u\]/s',
        '/\[s\](.*?)\[\/s\]/s',
        '/\[sub\](.*?)\[\/sub\]/s',
        '/\[sup\](.*?)\[\/sup\]/s',
        '/\[h1\](.*?)\[\/h1\]/s',
        '/\[h2\](.*?)\[\/h2\]/s',
        '/\[hr\]/s',
        '/\[hr=([a-z]+)\]/s', // Dynamic HR
        '/\[center\](.*?)\[\/center\]/s',
        '/\[right\](.*?)\[\/right\]/s',
        '/\[quote=(.*?)\](.*?)\[\/quote\]/s',
        '/\[quote\](.*?)\[\/quote\]/s',
        '/\[code\](.*?)\[\/code\]/s',
        
        // --- EXPANDED VISUALS ---
        '/\[accordion title=(.*?)\](.*?)\[\/accordion\]/s',
        '/\[list=fancy\](.*?)\[\/list\]/s',
        '/\[box=animated\](.*?)\[\/box\]/s',

        // --- MYRIAD CODES ---
        '/\[cmd\](.*?)\[\/cmd\]/s',
        '/\[ascii\](.*?)\[\/ascii\]/s',
        '/\[ghost\](.*?)\[\/ghost\]/s',
        '/\[mirror\](.*?)\[\/mirror\]/s',
        '/\[flip\](.*?)\[\/flip\]/s',
        '/\[updown\](.*?)\[\/updown\]/s',
        
        // GAME & ACTION TAGS
        '/\[me\](.*?)\[\/me\]/s',
        '/\[coin\](.*?)\[\/coin\]/s',
        '/\[roll\](.*?)\[\/roll\]/s',
        '/\[game=(.*?)\]/s',
        '/\[image=(\d+)\]/s',
        '/\[channel=(\d+)\]/s',
        '/\[url=(.*?)\](.*?)\[\/url\]/s',
        '/\[game=(.*?)\]/s',
        '/\[image=(\d+)\]/s',

        // FX TAGS
        '/\[spoiler\](.*?)\[\/spoiler\]/s',
        '/\[warning\](.*?)\[\/warning\]/s',
        '/\[redacted\](.*?)\[\/redacted\]/s',
        '/\[blur\](.*?)\[\/blur\]/s',
        '/\[glow\](.*?)\[\/glow\]/s',
        '/\[rainbow\](.*?)\[\/rainbow\]/s',
        '/\[glitch\](.*?)\[\/glitch\]/s',
        '/\[pulse\](.*?)\[\/pulse\]/s',
        '/\[shake\](.*?)\[\/shake\]/s',
        '/\[xmas\](.*?)\[\/xmas\]/s',
        '/\[greet\](.*?)\[\/greet\]/s',
    ];

    $replace = [
        '<strong>$1</strong>',
        '<em>$1</em>',
        '<u>$1</u>',
        '<span class="bb-strike">$1</span>',
        '<sub>$1</sub>',
        '<sup>$1</sup>',
        '<h2 style="color:var(--accent-secondary); border-bottom:1px solid var(--border-color); margin:15px 0 10px 0;">$1</h2>',
        '<h3 style="color:var(--accent-primary); margin:10px 0 5px 0;">$1</h3>',
        '<hr style="border:0; border-bottom:1px solid var(--border-color); margin:20px 0;">',
        '<hr class="hr-$1" style="border:0; height:2px; margin:20px 0; background:var(--accent-primary);">', // Simple fallback for HR
        '<div style="text-align:center; width:100%;">$1</div>',
        '<div style="text-align:right; width:100%;">$1</div>',
        '<blockquote style="border-left:3px solid var(--accent-primary); margin:10px 0; padding:10px 15px; background:var(--panel-bg); color:var(--text-main);"><strong style="color:var(--accent-secondary); display:block; margin-bottom:5px;">$1 wrote:</strong>$2</blockquote>',
        '<blockquote style="border-left:3px solid var(--accent-primary); margin:10px 0; padding:10px 15px; background:var(--panel-bg); color:var(--text-main);">$1</blockquote>',
        '<pre style="background:#080808; padding:10px; border:1px solid var(--border-color); overflow-x:auto; color:#ccc;">$1</pre>',
        
        // VISUALS
        '<details style="background:#111; border:1px solid #333; margin:10px 0;"><summary style="padding:10px; cursor:pointer; color:var(--accent-secondary); font-weight:bold; background:#1a1a1a;">$1</summary><div style="padding:15px; border-top:1px solid #333;">$2</div></details>',
        '<ul style="list-style:none; padding-left:10px;">$1</ul>', // Combined with [*] replacement below, this works
        '<div style="border:1px solid var(--accent-primary); padding:15px; box-shadow:0 0 10px rgba(106,156,106,0.2); margin:10px 0;">$1</div>',

        // MYRIAD REPLACEMENTS
        '<span class="bb-cmd">$1</span>',
        '<div class="bb-ascii">$1</div>',
        '<span class="bb-ghost">$1</span>',
        '<span class="bb-mirror">$1</span>',
        '<span class="bb-flip">$1</span>',
        '<span class="bb-upside">$1</span>',
        
        // GAME & ACTION REPLACEMENTS
        '<span class="bb-me">➜ $1</span>',
        '<span class="bb-coin"><span style="font-size:1.2em;">🪙</span> $1</span>',
        '<span class="bb-roll"><span style="font-size:1.2em;">🎲</span> $1</span>',
        '<a href="dots.php?id=$1" target="_blank" style="color:#e5c07b; text-decoration:none; border:1px solid #e5c07b; padding:0 4px;">[ 🎲 JOIN GAME ]</a>',
        '<a href="image_viewer.php?id=$1" target="_blank" style="color:#56b6c2; text-decoration:none; border:1px solid #56b6c2; padding:0 4px;">[ 🖼️ VIEW DATA ]</a>',
        // Channel Link
        '<a href="chat.php?set_channel=$1" target="_top" style="color:#e5c07b; text-decoration:none; border-bottom:1px dashed #e5c07b; font-weight:bold;">[ 📡 TUNE: FREQ $1 ]</a>',
        '<a href="$1" target="_blank" style="color:#6a9c6a; text-decoration:underline; font-weight:bold;">$2</a>',
        '<a href="dots.php?id=$1" target="_blank" style="color:#e5c07b; text-decoration:none; border:1px solid #e5c07b; padding:0 4px;">[ 🎲 JOIN GAME ]</a>',
        '<a href="image_viewer.php?id=$1" target="_blank" style="color:#56b6c2; text-decoration:none; border:1px solid #56b6c2; padding:0 4px;">[ 🖼️ VIEW DATA ]</a>',

        // FX REPLACEMENTS
        '<span class="bb-spoiler" title="Hover to reveal">$1</span>',
        '<div class="bb-warning">⚠ WARNING: $1</div>',
        '<span class="bb-redacted">$1</span>',
        '<span class="bb-blur">$1</span>',
        '<span class="text-glow">$1</span>',
        '<span class="anim-rainbow">$1</span>',
        '<span class="anim-glitch">$1</span>',
        '<span class="anim-pulse">$1</span>',
        '<span class="anim-shake">$1</span>',
        '<div style="background:#051020; border:1px solid #61afef; color:#fff; padding:10px; margin:10px 0; font-family:monospace; line-height:1.4;"><span style="color:#61afef; font-weight:bold; margin-right:10px;">[INFO]</span><span style="letter-spacing:2px;">🎄 <span style="color:#e06c75;">M</span><span style="color:#98c379;">E</span><span style="color:#e06c75;">R</span><span style="color:#e06c75;">R</span><span style="color:#98c379;">Y</span> <span style="color:#e06c75;">C</span><span style="color:#98c379;">H</span><span style="color:#e06c75;">R</span><span style="color:#98c379;">I</span><span style="color:#e06c75;">S</span><span style="color:#98c379;">T</span><span style="color:#e06c75;">M</span><span style="color:#98c379;">A</span><span style="color:#e06c75;">S</span> 🎄</span><br><span style="color:#61afef; font-size:1.2rem; filter:drop-shadow(0 0 5px #61afef);">❄ ❄ ❄ ❄ ❄ ❄ ❄</span><br>$1</div>',
        '<div style="padding:25px; margin:15px 0; border:1px dashed #333; background:rgba(255,255,255,0.02); text-align:center; font-family:monospace;">
            <div style="font-size:1.8rem; letter-spacing:8px; margin-bottom:15px; color:#fff; text-shadow:0 0 10px rgba(255,255,255,0.3);">$1</div>
            <div style="font-size:1.2rem; color:#666; letter-spacing:15px;">❄ ❄ ❄ ❄ ❄</div>
         </div>',
    ];

    $text = preg_replace($find, $replace, $text);

// 3) Complex Tags (Robust Box)
    $text = preg_replace(
        '/\[box=(.*?)\](.*?)\[\/box\]/s', 
        '<div style="background:#111; border:1px solid #333; padding:15px; margin:10px 0;"><strong style="color:#6a9c6a; display:block; border-bottom:1px solid #333; padding-bottom:5px; margin-bottom:10px;">$1</strong>$2</div>', 
        $text
    );
    $text = preg_replace('/\[color=(#[0-9a-fA-F]{3,6}|[a-z]+)\](.*?)\[\/color\]/s', '<span style="color:$1;">$2</span>', $text);
    $text = preg_replace('/\[size=([0-9\.]+)\](.*?)\[\/size\]/s', '<span style="font-size:$1em;">$2</span>', $text);
    $text = preg_replace('/\[font=(.*?)\](.*?)\[\/font\]/s', '<span style="font-family:$1;">$2</span>', $text);

    // 4) Lists (Robust Regex)
    $text = preg_replace('/\[list\](.*?)\[\/list\]/s', '<ul style="padding-left:20px; color:#ccc;">$1</ul>', $text);
    // Matches [*]content until next [*] or [/list]
    $text = preg_replace('/\[\*\](.*?)(?=(\[\*\]|\[\/list\]))/s', '<li>$1</li>', $text);

    // 5) Convert newlines for normal text ONLY
    $text = nl2br($text);

    // 5.5) AUTO-LINKIFY
    
    // A. Tor Onion Links (Raw) - Matches v2 (16 char) and v3 (56 char) addresses without http
    // We strictly look for strings ending in .onion that are NOT preceded by / (to avoid breaking existing URLs)
    $text = preg_replace(
        '/(?<!\/)\b([a-z2-7]{16}|[a-z2-7]{56})\.onion(\/[^\s<]*)?/i', 
        '<a href="http://$1.onion$2" target="_blank" style="color:#d19a66; text-decoration:underline;">$1.onion$2</a>', 
        $text
    );

    // B. Standard Links (http/https)
    $text = preg_replace(
        '/(https?:\/\/[^\s<]+)/i', 
        '<a href="$1" target="_blank" style="color:#6a9c6a; text-decoration:underline;">$1</a>', 
        $text
    );

    // C. @Username Tagging
    $text = preg_replace_callback('/@([a-zA-Z0-9_-]+)/', function($matches) use ($pdo) {
        $target_user = $matches[1];
        // Check registered users first
        $stmt = $pdo->prepare("SELECT chat_color FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$target_user]);
        $color = $stmt->fetchColumn();

        // Check guests if no registered user found
        if (!$color) {
            $stmtG = $pdo->prepare("SELECT guest_color FROM guest_tokens WHERE guest_username = ? LIMIT 1");
            $stmtG->execute([$target_user]);
            $color = $stmtG->fetchColumn();
        }

        if ($color) {
            return '<span class="mention-tag" style="color:' . $color . '; font-weight:bold; border-bottom:1px dotted ' . $color . ';">@' . htmlspecialchars($target_user) . '</span>';
        }
        return '@' . $target_user;
    }, $text);

    // 5.8) MENTIONS (User & Guest)
    // Fixes the "Unknown column 'username'" error by using guest_username
    $text = preg_replace_callback('/@([a-zA-Z0-9_-]+)/', function($matches) use ($pdo) {
        $target_user = $matches[1];
        
        // 1. Check Registered Users
        $stmt = $pdo->prepare("SELECT chat_color FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$target_user]);
        $color = $stmt->fetchColumn();

        // 2. Check Guests (Fix applied here)
        if (!$color) {
            $stmtG = $pdo->prepare("SELECT guest_color FROM guest_tokens WHERE guest_username = ? LIMIT 1");
            $stmtG->execute([$target_user]);
            $color = $stmtG->fetchColumn();
        }

        if ($color) {
            return '<span class="mention-tag" style="color:' . $color . '; font-weight:bold; border-bottom:1px dotted ' . $color . ';">@' . htmlspecialchars($target_user) . '</span>';
        }
        return '@' . $target_user;
    }, $text);

// 6) Restore PGP blocks as <pre> and auto-fix armor formatting for copy/decrypt
foreach ($pgp_blocks as $token => $raw) {

    // Normalize line endings early
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // If someone pasted PGP in one line with spaces, fix it into real armored format
    $raw = format_pgp_armor($raw);

    // Escape safely (keeps exact characters/newlines)
    $safe = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $text = str_replace($token, '<pre class="bb-pgp">' . $safe . '</pre>', $text);
}

return $text;

}
?>
