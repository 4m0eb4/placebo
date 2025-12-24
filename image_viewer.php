<?php
session_start();
require 'db_config.php';
require 'bbcode.php'; 

// --- AUTH CHECK ---
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// --- HELPERS ---
function get_upload_score($pdo, $uid) {
    return (int)$pdo->query("SELECT SUM(vote) FROM upload_votes WHERE upload_id = $uid")->fetchColumn();
}

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

// --- PERMISSIONS ---
// Fetch admin delete permission rank from settings (default Rank 9)
$perm_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'permissions_config'");
$perms = json_decode($perm_stmt->fetchColumn() ?: '{}', true);
$req_del = $perms['perm_delete_upload'] ?? 9; 

$can_delete = ($my_id == $upload['user_id'] || $my_rank >= $req_del);

// --- HANDLING POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. DELETE UPLOAD
    if (isset($_POST['delete_post']) && $can_delete) {
        // Delete file
        $path = __DIR__ . '/uploads/image/' . $upload['disk_filename'];
        if (file_exists($path)) @unlink($path);
        
        // Clean DB
        $pdo->prepare("DELETE FROM uploads WHERE id = ?")->execute([$upload_id]);
        $pdo->prepare("DELETE FROM upload_comments WHERE upload_id = ?")->execute([$upload_id]);
        $pdo->prepare("DELETE FROM upload_votes WHERE upload_id = ?")->execute([$upload_id]);
        
        header("Location: gallery.php"); exit;
    }

    // 2. VOTE ON UPLOAD
    if (isset($_POST['vote_val'])) {
        $val = (int)$_POST['vote_val']; // 1 or -1
        if (abs($val) === 1) {
            // Toggle Logic
            $chk = $pdo->prepare("SELECT vote FROM upload_votes WHERE upload_id = ? AND user_id = ?");
            $chk->execute([$upload_id, $my_id]);
            $exist = $chk->fetchColumn();
            
            if ($exist == $val) {
                $pdo->prepare("DELETE FROM upload_votes WHERE upload_id = ? AND user_id = ?")->execute([$upload_id, $my_id]);
            } else {
                $pdo->prepare("INSERT INTO upload_votes (upload_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = ?")
                    ->execute([$upload_id, $my_id, $val, $val]);
            }
        }
        header("Location: image_viewer.php?id=$upload_id"); exit;
    }

    // 3. POST COMMENT
    if (isset($_POST['comment_body'])) {
        $body = trim($_POST['comment_body']);
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if ($body) {
            $pdo->prepare("INSERT INTO upload_comments (upload_id, user_id, body, parent_id) VALUES (?, ?, ?, ?)")
                ->execute([$upload_id, $my_id, $body, $parent]);
        }
        header("Location: image_viewer.php?id=$upload_id#comments"); exit;
    }
    
    // 4. DELETE COMMENT
    if (isset($_POST['delete_comment'])) {
        $cid = (int)$_POST['delete_comment'];
        // Check ownership/perms
        $c_chk = $pdo->prepare("SELECT user_id FROM upload_comments WHERE id = ?");
        $c_chk->execute([$cid]);
        $c_owner = $c_chk->fetchColumn();
        
        if ($c_owner == $my_id || $my_rank >= $req_del) {
            $pdo->prepare("DELETE FROM upload_comments WHERE id = ?")->execute([$cid]);
        }
        header("Location: image_viewer.php?id=$upload_id#comments"); exit;
    }
}

// --- DATA PREP ---
$score = get_upload_score($pdo, $upload_id);
$my_vote = $pdo->query("SELECT vote FROM upload_votes WHERE upload_id=$upload_id AND user_id=$my_id")->fetchColumn();

// Fetch Comments
$c_stmt = $pdo->prepare("
    SELECT c.*, u.username, u.rank, u.chat_color 
    FROM upload_comments c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.upload_id = ? 
    ORDER BY c.created_at ASC
");
$c_stmt->execute([$upload_id]);
$all_comments = $c_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>VIEWER // <?= htmlspecialchars($upload['title'] ?: 'IMG_'.$upload_id) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .viewer-grid { display: grid; grid-template-columns: 1fr 300px; gap: 20px; height: calc(100vh - 60px); }
        .img-container { background: #000; border: 1px solid #222; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
        .img-container img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .meta-panel { background: #111; border-left: 1px solid #333; display: flex; flex-direction: column; overflow: hidden; }
        .comment-scroll { flex: 1; overflow-y: auto; padding: 15px; }
        .comment-input { padding: 15px; border-top: 1px solid #333; background: #161616; }
        
        .c-row { margin-bottom: 10px; border-left: 2px solid #333; padding-left: 8px; }
        .c-head { font-size: 0.7rem; color: #666; display: flex; justify-content: space-between; margin-bottom: 2px; }
        .c-body { font-size: 0.8rem; color: #ccc; word-break: break-word; }
        
        .vote-bar { display: flex; align-items: center; gap: 10px; margin-top: 10px; font-family: monospace; }
        .vote-btn { background: none; border: 1px solid #333; color: #555; cursor: pointer; padding: 2px 8px; }
        .vote-btn.active-up { color: #6a9c6a; border-color: #6a9c6a; }
        .vote-btn.active-down { color: #e06c75; border-color: #e06c75; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" style="padding: 20px; overflow: hidden;">

    <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <span class="term-title">IMG_VIEWER</span> 
            <span style="color: #666; font-family: monospace;">// <?= htmlspecialchars($upload['original_filename']) ?></span>
        </div>
        <a href="gallery.php" style="color: #ccc; text-decoration: none; font-size: 0.8rem;">[ RETURN TO GRID ]</a>
    </div>

    <div class="viewer-grid">
        <div class="img-container">
            <img src="uploads/image/<?= htmlspecialchars($upload['disk_filename']) ?>">
            
            <?php if($can_delete): ?>
            <div style="position: absolute; top: 10px; right: 10px;">
                <form method="POST" onsubmit="return confirm('PERMANENTLY DELETE THIS FILE?');">
                    <button type="submit" name="delete_post" style="background: #220505; color: #e06c75; border: 1px solid #e06c75; padding: 5px 10px; cursor: pointer; font-weight: bold;">DELETE FILE</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="meta-panel">
            <div style="padding: 15px; border-bottom: 1px solid #333;">
                <h2 style="margin: 0 0 5px 0; font-size: 1rem; color: #e5c07b;"><?= htmlspecialchars($upload['title'] ?: 'Untitled') ?></h2>
                <div style="font-size: 0.7rem; color: #888; margin-bottom: 10px;">
                    UPLOADER: <span style="color: #ccc;"><?= htmlspecialchars($upload['username']) ?></span> 
                    (<?= date('M d, H:i', strtotime($upload['created_at'])) ?>)
                </div>
                
                <div class="vote-bar">
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="vote_val" value="1">
                        <button class="vote-btn <?= $my_vote==1 ? 'active-up':'' ?>">▲</button>
                    </form>
                    <span style="font-size: 1rem; font-weight: bold; color: #fff;"><?= $score ?></span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="vote_val" value="-1">
                        <button class="vote-btn <?= $my_vote==-1 ? 'active-down':'' ?>">▼</button>
                    </form>
                </div>
            </div>

            <div class="comment-scroll" id="comments">
                <?php 
                function render_img_comments($comments, $parent_id=null, $depth=0, $perm_del=false, $my_id=0) {
                    foreach($comments as $c) {
                        if($c['parent_id'] == $parent_id) {
                            $pad = $depth * 15;
                            $can_del = ($c['user_id'] == $my_id || $perm_del);
                            echo "<div class='c-row' style='margin-left: {$pad}px;'>
                                    <div class='c-head'>
                                        <span style='color:{$c['chat_color']}; font-weight:bold;'>{$c['username']}</span>
                                        <span>
                                            ".($can_del ? "<form method='POST' style='display:inline;' onsubmit=\"return confirm('Del?');\"><input type='hidden' name='delete_comment' value='{$c['id']}'><button style='background:none; border:none; color:#e06c75; cursor:pointer; font-size:0.6rem;'>[x]</button></form>" : "")."
                                            <a href='image_viewer.php?id={$c['upload_id']}&reply={$c['id']}#reply_box' style='color:#6a9c6a; text-decoration:none;'>[R]</a>
                                        </span>
                                    </div>
                                    <div class='c-body'>".parse_bbcode($c['body'])."</div>
                                  </div>";
                            render_img_comments($comments, $c['id'], $depth+1, $perm_del, $my_id);
                        }
                    }
                }
                if(empty($all_comments)) echo "<div style='color:#444; font-style:italic; text-align:center;'>No signal data.</div>";
                else render_img_comments($all_comments, null, 0, ($my_rank >= $req_del), $my_id);
                ?>
            </div>

            <div class="comment-input" id="reply_box">
                <?php 
                    $rep_id = $_GET['reply'] ?? null;
                    if($rep_id) echo "<div style='color:#6a9c6a; font-size:0.7rem; margin-bottom:5px;'>Replying to ID #$rep_id <a href='image_viewer.php?id=$upload_id' style='color:#e06c75; text-decoration:none;'>[CANCEL]</a></div>";
                ?>
                <form method="POST">
                    <?php if($rep_id): ?><input type="hidden" name="parent_id" value="<?= $rep_id ?>"><?php endif; ?>
                    <input type="text" name="comment_body" placeholder="Add annotation..." required autocomplete="off" style="width: 100%; background: #000; border: 1px solid #333; color: #fff; padding: 8px; box-sizing: border-box;">
                    <button type="submit" class="btn-primary" style="margin-top: 5px; width: 100%; padding: 5px;">TRANSMIT</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>