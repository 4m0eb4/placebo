<?php
session_start();
require 'db_config.php';
require 'bbcode.php'; 

// --- AUTH CHECK ---
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// --- RANK CHECK ---
$g_req = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'gallery_min_rank'")->fetchColumn();
$g_req = (int)($g_req ?: 5);
if (($_SESSION['rank'] ?? 0) < $g_req) { die("<body style='background:#000;color:#e06c75;display:flex;justify-content:center;align-items:center;height:100vh;font-family:monospace;'>ACCESS DENIED: RANK $g_req REQUIRED</body>"); }

// --- INIT ---
$upload_id = (int)($_GET['id'] ?? 0);
if (!$upload_id) die("INVALID SIGNAL.");

$my_id = $_SESSION['user_id'];
$my_rank = $_SESSION['rank'] ?? 1;

// --- FETCH UPLOAD ---
$stmt = $pdo->prepare("SELECT u.*, us.username, us.rank as uploader_rank FROM uploads u JOIN users us ON u.user_id = us.id WHERE u.id = ?");
$stmt->execute([$upload_id]);
$upload = $stmt->fetch();

if (!$upload) die("TRANSMISSION LOST OR DELETED.");

// --- CONFIG ---
$file_dir = 'uploads/doc/';
$file_path = __DIR__ . '/' . $file_dir . $upload['disk_filename'];

// --- DOWNLOAD HANDLER (Secure Headers) ---
if (isset($_GET['dl'])) {
    if (file_exists($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.($upload['original_filename'] ?: 'document.bin').'"');
        header('Content-Length: ' . filesize($file_path));
        header('Pragma: public');
        readfile($file_path);
        exit;
    } else {
        die("FILE NOT FOUND ON DISK.");
    }
}

// --- PERMISSIONS ---
$perm_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($perm_stmt->fetchColumn() ?: '{}', true);
$req_del = $perms['perm_delete_upload'] ?? 9; 
$can_delete = ($my_id == $upload['user_id'] || $my_rank >= $req_del);

// --- HANDLING POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. DELETE UPLOAD
    if (isset($_POST['delete_post']) && $can_delete) {
        if (file_exists($file_path)) @unlink($file_path);
        
        $pdo->prepare("DELETE FROM uploads WHERE id = ?")->execute([$upload_id]);
        $pdo->prepare("DELETE FROM upload_comments WHERE upload_id = ?")->execute([$upload_id]);
        $pdo->prepare("DELETE FROM upload_votes WHERE upload_id = ?")->execute([$upload_id]);
        
        header("Location: gallery.php?view=doc"); exit;
    }

    // 2. VOTE ON UPLOAD
    if (isset($_POST['vote_val'])) {
        $val = (int)$_POST['vote_val'];
        if (abs($val) === 1) {
            $chk = $pdo->prepare("SELECT vote FROM upload_votes WHERE upload_id = ? AND user_id = ?");
            $chk->execute([$upload_id, $my_id]);
            $exist = $chk->fetchColumn();
            
            if ($exist == $val) {
                $pdo->prepare("DELETE FROM upload_votes WHERE upload_id = ? AND user_id = ?")->execute([$upload_id, $my_id]);
            } else {
                $pdo->prepare("INSERT INTO upload_votes (upload_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = ?")->execute([$upload_id, $my_id, $val, $val]);
            }
        }
        header("Location: file_view.php?id=$upload_id"); exit;
    }

    // 3. POST COMMENT
    if (isset($_POST['comment_body'])) {
        $body = trim($_POST['comment_body']);
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($body) {
            $pdo->prepare("INSERT INTO upload_comments (upload_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)")->execute([$upload_id, $my_id, $body, $parent]);
        }
        header("Location: file_view.php?id=$upload_id#comments"); exit;
    }
    
    // 4. DELETE COMMENT
    if (isset($_POST['delete_comment'])) {
        $cid = (int)$_POST['delete_comment'];
        $c_chk = $pdo->prepare("SELECT user_id FROM upload_comments WHERE id = ?");
        $c_chk->execute([$cid]);
        $c_owner = $c_chk->fetchColumn();
        if ($c_owner == $my_id || $my_rank >= $req_del) {
            $pdo->prepare("DELETE FROM upload_comments WHERE id = ?")->execute([$cid]);
        }
        header("Location: file_view.php?id=$upload_id#comments"); exit;
    }

    // 5. VOTE ON COMMENT
    if (isset($_POST['vote_comment_id'])) {
        $cid = (int)$_POST['vote_comment_id'];
        $val = (int)$_POST['vote_val'];
        if (abs($val) === 1) {
            $chk = $pdo->prepare("SELECT vote FROM comment_votes WHERE comment_id = ? AND user_id = ?");
            $chk->execute([$cid, $my_id]);
            $exist = $chk->fetchColumn();
            if ($exist == $val) {
                $pdo->prepare("DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?")->execute([$cid, $my_id]);
            } else {
                $pdo->prepare("INSERT INTO comment_votes (comment_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = ?")->execute([$cid, $my_id, $val, $val]);
            }
        }
        header("Location: file_view.php?id=$upload_id#c-$cid"); exit;
    }
}

// --- DATA PREP ---
$score = (int)$pdo->query("SELECT SUM(vote) FROM upload_votes WHERE upload_id = $upload_id")->fetchColumn();
$my_vote = $pdo->query("SELECT vote FROM upload_votes WHERE upload_id=$upload_id AND user_id=$my_id")->fetchColumn();

$c_stmt = $pdo->prepare("SELECT c.*, u.username, u.rank, u.chat_color, (SELECT COALESCE(SUM(vote),0) FROM comment_votes WHERE comment_id = c.id) as score, (SELECT vote FROM comment_votes WHERE comment_id = c.id AND user_id = ?) as my_vote FROM upload_comments c JOIN users u ON c.user_id = u.id WHERE c.upload_id = ? ORDER BY c.created_at ASC");
$c_stmt->execute([$my_id, $upload_id]);
$all_comments = $c_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>DOC // <?= htmlspecialchars($upload['title'] ?: 'FILE_'.$upload_id) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #0d0d0d; color: #ccc; font-family: monospace; overflow-y: scroll; padding-bottom: 50px; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .meta-bar { background: #111; border: 1px solid #333; padding: 15px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-start; }
        .c-row { margin-top: 15px; border-left: 2px solid #333; padding-left: 15px; }
        .c-head { background: #111; padding: 5px; border: 1px solid #222; display: flex; justify-content: space-between; font-size: 0.75rem; }
        .c-body { background: #0a0a0a; border: 1px solid #222; border-top: none; padding: 10px; color: #ccc; word-break: break-word; }
        .vote-btn { background: #000; border: 1px solid #333; color: #555; cursor: pointer; padding: 2px 8px; font-weight: bold; }
        .vote-btn:hover { color: #fff; border-color: #666; }
        .dl-box { background: #050505; border: 1px dashed #333; padding: 40px; text-align: center; margin-bottom: 20px; }
        .btn-dl { background: #1a1a1a; border: 1px solid #6a9c6a; color: #6a9c6a; padding: 10px 20px; text-decoration: none; font-weight: bold; display: inline-block; }
        .btn-dl:hover { background: #6a9c6a; color: #000; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>">
    <div class="container">
        
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span class="term-title">FILE_VIEWER // DOCUMENT</span>
            <a href="gallery.php?view=doc" style="color: #666; text-decoration: none;">[ RETURN ]</a>
        </div>

        <div class="dl-box">
            <div style="font-size: 2rem; margin-bottom: 10px; color: #444;">[ DOC ]</div>
            <div style="margin-bottom: 20px; color: #888;"><?= htmlspecialchars($upload['original_filename']) ?></div>
            
            <a href="?id=<?= $upload_id ?>&dl=1" class="btn-dl">[ DOWNLOAD SECURELY ]</a>

            <?php if($can_delete): ?>
                <form method="POST" style="margin-top:20px;">
                    <button type="submit" name="delete_post" style="background: #220505; color: #e06c75; border: 1px solid #e06c75; padding: 5px 10px; cursor: pointer; font-weight: bold;">[ DELETE FILE ]</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="meta-bar">
            <div>
                <h1 style="color: #e5c07b; margin: 0 0 5px 0; font-size: 1.2rem;"><?= htmlspecialchars($upload['title'] ?: 'Untitled') ?></h1>
                <div style="font-size: 0.75rem; color: #888;">
                    UPLOADER: <span style="color: #ccc;"><?= htmlspecialchars($upload['username']) ?></span> | 
                    SIZE: <?= round($upload['file_size']/1024) ?> KB | 
                    DATE: <?= date('Y-m-d H:i', strtotime($upload['created_at'])) ?>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="display:flex; align-items:center; gap:5px;">
                    <form method="POST" style="margin:0;"><input type="hidden" name="vote_val" value="1"><button class="vote-btn" style="<?= $my_vote==1?'color:#6a9c6a;border-color:#6a9c6a':'' ?>">▲</button></form>
                    <span style="font-size: 1.2rem; font-weight: bold; width: 30px; text-align: center;"><?= $score ?></span>
                    <form method="POST" style="margin:0;"><input type="hidden" name="vote_val" value="-1"><button class="vote-btn" style="<?= $my_vote==-1?'color:#e06c75;border-color:#e06c75':'' ?>">▼</button></form>
                </div>
            </div>
        </div>

        <div id="comments" style="margin-bottom: 50px;">
            <h3 style="color: #6a9c6a; border-bottom: 1px dashed #333; padding-bottom: 10px;">DATA ANNOTATIONS (<?= count($all_comments) ?>)</h3>

            <?php 
            function render_comments($comments, $parent_id=null, $depth=0, $perm_del=false, $my_id=0) {
                foreach($comments as $c) {
                    if($c['parent_id'] == $parent_id) {
                        $pad = $depth * 30;
                        $can_del = ($c['user_id'] == $my_id || $perm_del);
                        $up_style = ($c['my_vote'] == 1) ? 'color:#6a9c6a;' : 'color:#555;';
                        $down_style = ($c['my_vote'] == -1) ? 'color:#e06c75;' : 'color:#555;';
                        $score_col = ($c['score'] > 0) ? '#6a9c6a' : (($c['score'] < 0) ? '#e06c75' : '#888');

                        echo "<div class='c-row' id='c-{$c['id']}' style='margin-left: {$pad}px;'>
                                <div class='c-head'>
                                    <div style='display:flex; gap:10px; align-items:center;'>
                                        <div style='display:flex; align-items:center; gap:2px; background:#000; padding:2px 5px; border:1px solid #222;'>
                                            <form method='POST' style='margin:0;'><input type='hidden' name='vote_comment_id' value='{$c['id']}'><input type='hidden' name='vote_val' value='1'><button style='background:none; border:none; cursor:pointer; font-weight:bold; $up_style'>▲</button></form>
                                            <span style='color:$score_col; font-weight:bold; min-width:15px; text-align:center;'>{$c['score']}</span>
                                            <form method='POST' style='margin:0;'><input type='hidden' name='vote_comment_id' value='{$c['id']}'><input type='hidden' name='vote_val' value='-1'><button style='background:none; border:none; cursor:pointer; font-weight:bold; $down_style'>▼</button></form>
                                        </div>
                                        <span style='color:{$c['chat_color']}; font-weight:bold;'>{$c['username']}</span>
                                    </div>
                                    <span>{$c['created_at']}</span>
                                </div>
                                <div class='c-body'>
                                    ".parse_bbcode($c['body'])."
                                    <div style='margin-top:5px; text-align:right; font-size:0.7rem;'>
                                        ".($can_del ? "<form method='POST' style='display:inline;'><input type='hidden' name='delete_comment' value='{$c['id']}'><button style='background:none; border:none; color:#e06c75; cursor:pointer;'>[ DELETE ]</button></form>" : "")."
                                        <a href='file_view.php?id={$c['upload_id']}&reply={$c['id']}#reply_box' style='color:#6a9c6a; text-decoration:none; margin-left:10px;'>[ REPLY ]</a>
                                    </div>
                                </div>
                              </div>";
                        render_comments($comments, $c['id'], $depth+1, $perm_del, $my_id);
                    }
                }
            }
            if(empty($all_comments)) echo "<div style='color:#444; padding:20px; text-align:center;'>No data found.</div>";
            else render_comments($all_comments, null, 0, ($my_rank >= $req_del), $my_id);
            ?>
        </div>

        <div id="reply_box" style="background: #111; padding: 20px; border: 1px solid #333;">
            <?php 
                $rep_id = $_GET['reply'] ?? null;
                if($rep_id) echo "<div style='color:#6a9c6a; font-size:0.8rem; margin-bottom:10px;'>Replying to ID #$rep_id <a href='file_view.php?id=$upload_id' style='color:#e06c75; text-decoration:none;'>[CANCEL]</a></div>";
            ?>
            <form method="POST">
                <?php if($rep_id): ?><input type="hidden" name="parent_id" value="<?= $rep_id ?>"><?php endif; ?>
                <input type="text" name="comment_body" placeholder="Post a comment..." required autocomplete="off" style="width: 100%; background: #000; border: 1px solid #333; color: #fff; padding: 10px; font-family: monospace; box-sizing: border-box;">
                <button type="submit" class="btn-primary" style="margin-top: 10px; width: auto; padding: 8px 20px; background:#111; color:#fff; border:1px solid #333; cursor:pointer;">TRANSMIT</button>
            </form>
        </div>
    </div>
</body>
</html>