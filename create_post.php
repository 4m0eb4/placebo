<?php
session_start();
require 'db_config.php';
require 'bbcode.php'; // Load Engine

// Security: Dynamic Permissions
$sys_perms = [];
try {
    $raw = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'")->fetchColumn();
    $sys_perms = json_decode($raw, true) ?? [];
} catch(Exception $e){}

$req_post = $sys_perms['perm_create_post'] ?? 9;

if (!isset($_SESSION['rank']) || $_SESSION['rank'] < $req_post) {
    die("
    <body style='background:#0d0d0d; color:#e06c75; font-family:monospace; display:flex; align-items:center; justify-content:center; height:100vh;'>
        <div style='border:1px solid #e06c75; padding:20px; text-align:center;'>
            <h2 style='margin:0;'>ACCESS DENIED</h2>
            <p>POSTING REQUIRES RANK $req_post+.</p>
            <a href='index.php' style='color:#fff;'>[ RETURN ]</a>
        </div>
    </body>");
}

// EDIT MODE logic
$edit_id = $_GET['edit'] ?? null;
$title_val = '';
$body_val = '';
$rank_val = 1; // Default Public
$cutoff_val = 250; // Default Cutoff
$pinned_val = 0; // Default Not Pinned
$pin_weight_val = 0; // Default Weight
$mode_label = 'NEW_POST';

// Load existing post if editing
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([$edit_id]);
    $post = $stmt->fetch();
    if($post) {
        $title_val = $post['title'];
        $body_val = $post['body'];
        $rank_val = $post['min_rank'];
        $cutoff_val = $post['preview_cutoff'] ?? 250;
        $pinned_val = $post['is_pinned'] ?? 0;
        $pin_weight_val = $post['pin_weight'] ?? 0; 
        $mode_label = 'EDIT_POST // ID: ' . $edit_id;
    }
}

$msg = '';
$preview_html = '';

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capture Input
    $title_val = trim($_POST['title']);
    $body_val = trim($_POST['body']);
    
    // [MODIFIED] Allow Rank 0 (Guest) minimum.
    $rank_input = (int)($_POST['min_rank'] ?? 1);
    $rank_val = ($rank_input < 0) ? 0 : $rank_input;
    
    $cutoff_val = (int)($_POST['cutoff'] ?? 250);
    $pinned_val = isset($_POST['is_pinned']) ? 1 : 0;
    $p_weight = (int)($_POST['pin_weight'] ?? 0);
    
    // 2. ACTION: PREVIEW
    if (isset($_POST['action_preview'])) {
        if ($title_val && $body_val) {
// Added overflow-wrap for preview
            $preview_html = "
            <div style='border:1px dashed #6a9c6a; padding:15px; margin-bottom:20px; background:#051005;'>
                <div style='color:#e0e0e0; font-weight:bold; border-bottom:1px solid #333; margin-bottom:10px; font-size:0.9rem; overflow-wrap: anywhere; word-break: break-word;'>
                    PREVIEW: " . htmlspecialchars($title_val) . "
                </div>
                <div style='font-family:monospace; color:#ccc; line-height:1.5; font-size:0.9rem; overflow-wrap: anywhere; word-break: break-word;'>" . parse_bbcode($body_val) . "</div>
            </div>";
        } else {
            $msg = "Title and Body required for preview.";
        }
    }

    // 3. ACTION: BROADCAST (SAVE)
    if (isset($_POST['action_post'])) {
        if ($title_val && $body_val) {
            if ($edit_id) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE posts SET title=?, body=?, min_rank=?, preview_cutoff=?, is_pinned=?, pin_weight=? WHERE id=?");
                $stmt->execute([$title_val, $body_val, $rank_val, $cutoff_val, $pinned_val, $p_weight, $edit_id]);
                
                // Log it (Identity Protected)
                if(isset($_SESSION['user_id'])) {
                    $log_ident = "SID:" . substr(session_id(), 0, 6) . "..";
                    
                    $pdo->prepare("INSERT INTO security_logs (user_id, username, action, ip_addr) VALUES (?, ?, ?, ?)")
                        ->execute([$_SESSION['user_id'], $_SESSION['username'], "Edited Post #$edit_id", $log_ident]);
                }
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO posts (user_id, title, body, min_rank, preview_cutoff, is_pinned, pin_weight) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $title_val, $body_val, $rank_val, $cutoff_val, $pinned_val, $p_weight]);
            }
            header("Location: admin_dash.php?view=posts"); exit;
        } else {
            $msg = "Title and Body required.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Comms Uplink</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper" style="width: 900px;">
    <div class="terminal-header">
        <span class="term-title">COMMS_UPLINK // <?= $mode_label ?></span>
        <a href="admin_dash.php?view=posts" style="color:#666; font-size:0.7rem;">[ ABORT ]</a>
    </div>
    
    <div style="padding: 20px;">
        <?php if($msg): ?><div class="error"><?= $msg ?></div><?php endif; ?>
        
        <?= $preview_html ?>
        
        <form method="POST">
            <div style="display:grid; grid-template-columns: 3fr 1fr; gap: 20px;">
                <div class="input-group">
                    <label>HEADER / TITLE</label>
                    <input type="text" name="title" required value="<?= htmlspecialchars($title_val) ?>" style="background:#0d0d0d;">
                </div>
                <div class="input-group">
                    <label>CLEARANCE LEVEL (MIN RANK)</label>
                    <select name="min_rank" style="background:#0d0d0d; width:100%; border:1px solid #333; color:#ccc; padding:12px; border-radius:4px;">
                        <option value="0" <?= $rank_val==0?'selected':'' ?>>Rank 0 (Guest / Open)</option>
                        <option value="1" <?= $rank_val==1?'selected':'' ?>>Rank 1 (User / Public)</option>
                        <option value="5" <?= $rank_val==5?'selected':'' ?>>Rank 5 (Privileged)</option>
                        <option value="9" <?= $rank_val==9?'selected':'' ?>>Rank 9 (Admin)</option>
                        <option value="10" <?= $rank_val==10?'selected':'' ?>>Rank 10 (Eyes Only)</option>
                    </select>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
                <div class="input-group">
                    <label>PREVIEW LENGTH</label>
                    <input type="number" name="cutoff" value="<?= $cutoff_val ?>" style="background:#0d0d0d; border:1px solid #333; color:#888; padding:10px;" placeholder="200">
                    <small style="color:#555; font-size:0.6rem;">Chars visible before 'Read More'.</small>
                </div>
                
                <div class="input-group">
                     <label>FEED PRIORITY</label>
                     <div style="display:flex; align-items:stretch; border:1px solid #333; background:#0d0d0d;">
                        <label style="cursor:pointer; display:flex; align-items:center; padding:0 15px; background:#161616; border-right:1px solid #333;">
                            <input type="checkbox" name="is_pinned" value="1" <?= ($pinned_val??0) ? 'checked' : '' ?> style="accent-color:#e5c07b;">
                        </label>
                        <div style="flex-grow:1; display:flex; align-items:center; padding:0 10px; color:#555; font-size:0.75rem;">
                            PINNED?
                        </div>
                        <input type="number" name="pin_weight" value="<?= $pin_weight_val ?? 0 ?>" placeholder="0" style="width:60px; background:transparent; border:none; border-left:1px solid #333; color:#e5c07b; padding:10px; text-align:center; font-weight:bold;">
                     </div>
                     <small style="color:#555; font-size:0.6rem;">Check box to Pin. Number = Rank (99 = Top).</small>
                </div>
            </div>
            
            <div class="input-group">
                <label>MESSAGE BODY</label>
                
                <details style="margin-bottom:10px; border:1px solid #333; background:#111;">
                    <summary style="padding:8px 15px; cursor:pointer; color:#6a9c6a; font-size:0.7rem; font-weight:bold;">[+] OPEN BBCODE CHEATSHEET (ENHANCED)</summary>
                    <div style="padding:15px; display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; font-size:0.65rem; color:#ccc;">
                        <div>[b]Bold[/b]</div>
                        <div>[i]Italic[/i]</div>
                        <div>[u]Underline[/u]</div>
                        <div>[s]Strikethrough[/s]</div>
                        
                        <div>[h1]Header[/h1]</div>
                        <div>[color=red]Text[/color]</div>
                        <div>[size=5]Big[/size]</div>
                        <div>[center]Text[/center]</div>

                        <div style="color:#e5c07b;">[quote]...[/quote]</div>
                        <div style="color:#e5c07b;">[code]...[/code]</div>
                        <div style="color:#e5c07b;">[img]url[/img]</div>
                        <div style="color:#e5c07b;">[url=link]T[/url]</div>

                        <div style="color:#56b6c2;">[accordion] [panel=Title]Content[/panel] [/accordion]</div>
                        <div style="color:#56b6c2;">[spoiler]Hidden[/spoiler]</div>
                        <div style="color:#56b6c2;">[blur]Blurry[/blur]</div>
                        <div style="color:#56b6c2;">[redacted]Blackout[/redacted]</div>

                        <div style="color:#e06c75;">[glitch]Text[/glitch]</div>
                        <div style="color:#e06c75;">[shake]Text[/shake]</div>
                        <div style="color:#e06c75;">[rainbow]Text[/rainbow]</div>
                        <div style="color:#e06c75;">[pulse]Text[/pulse]</div>

                        <div style="grid-column: 1/-1; border-top:1px dashed #333; padding-top:5px; margin-top:5px; color:#888;">
                            <strong>Special:</strong> [pgp]...[/pgp] (Preserves format), [cmd]command[/cmd], [ascii]art[/ascii], [box=animated]Content[/box]
                        </div>
                    </div>
                </details>

                <textarea name="body" class="pgp-box" style="height: 400px; background:#0d0d0d; font-size: 0.9rem; font-family:monospace;" required><?= htmlspecialchars($body_val) ?></textarea>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:15px; border-top:1px solid #333; padding-top:20px;">
                <button type="submit" name="action_preview" class="btn-primary" style="background:#1a1a1a; color:#ccc; border-color:#555;">PREVIEW SIGNAL</button>
                <button type="submit" name="action_post" class="btn-primary">BROADCAST MESSAGE</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>