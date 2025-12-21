<?php
session_start();
require 'db_config.php'; // Required for Database & Theme Loading
require 'bbcode.php';    // Required for formatting

// Authenticate
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// Fetch Post
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();

if (!$post) die("TRANSMISSION NOT FOUND.");

// Check Rank Clearance
$my_rank = $_SESSION['rank'] ?? 1;
if ($post['min_rank'] > $my_rank) {
    die("ACCESS DENIED: INSUFFICIENT CLEARANCE (LEVEL {$post['min_rank']} REQUIRED).");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($post['title']) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>body { display: block !important; }</style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display: block;">

<div class="main-container" style="width: 800px; margin: 0 auto;">
    
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a>
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Archive</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <a href="chat.php" style="color:#888; margin-right:10px; text-decoration:none;">[ CHAT ]</a>
                <a href="index.php" style="color:#888; text-decoration:none;">[ HOME ]</a>
            </div>
        </div>
        <div class="nav-links" style="font-size: 0.75rem; font-family: monospace;">
             <a href="logout.php">LOGOUT</a>
        </div>
    </div>

    <div style="padding: 30px; background: #0d0d0d; min-height: 80vh;">
        <div style="background: var(--panel-bg); border: 1px solid var(--border-color); padding: 30px; border-radius: 4px;">
            <h1 style="color: var(--accent-primary); margin-top: 0; display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 20px;">
                <span><?= parse_bbcode($post['title']) ?></span>
                <?php if($post['min_rank'] > 1): ?>
                    <span style="font-size:0.6rem; border:1px solid var(--accent-secondary); color:var(--accent-secondary); padding:4px 8px; border-radius:4px; letter-spacing:1px;">
                        LEVEL <?= $post['min_rank'] ?>
                    </span>
                <?php endif; ?>
            </h1>
            
            <div style="margin-bottom: 25px; font-size: 0.75rem; color: #555;">
                TRANSMISSION BY: <span style="color:var(--accent-secondary);"><?= htmlspecialchars($post['username']) ?></span> 
                <span style="margin: 0 10px;">|</span> 
                DATE: <?= $post['created_at'] ?>
            </div>
            
            <div style="line-height: 1.6; color: var(--text-main); font-family: inherit; font-size: 0.9rem;">
                <?= parse_bbcode($post['body']) ?>
            </div>
            
            <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                <div style="margin-top: 40px; border-top: 1px dashed #333; padding-top: 15px; text-align: right;">
                     <a href="create_post.php?edit=<?= $post['id'] ?>" style="color: #e06c75; font-size: 0.7rem; text-decoration: none; border: 1px solid #e06c75; padding: 5px 10px;">[ EDIT TRANSMISSION ]</a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // 1. Handle New Comment
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_comment'])) {
            $c_body = trim($_POST['comment_body']);
            $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            
            if ($c_body) {
                $stmt = $pdo->prepare("INSERT INTO post_comments (post_id, user_id, parent_id, body) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $_SESSION['user_id'], $parent, $c_body]);
                // Redirect to avoid resubmission
                header("Location: post.php?id=$id#comments"); exit;
            }
        }

        // 2. Fetch Comments
        $c_stmt = $pdo->prepare("
            SELECT c.*, u.username, u.rank, u.chat_color 
            FROM post_comments c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.post_id = ? 
            ORDER BY c.created_at ASC
        ");
        $c_stmt->execute([$id]);
        $all_comments = $c_stmt->fetchAll();

        // 3. Recursive Render Function
        function render_comments($comments, $parent_id = null, $depth = 0) {
            foreach ($comments as $c) {
                if ($c['parent_id'] == $parent_id) {
                    $margin = $depth * 20;
                    $border_color = ($depth > 0) ? '#333' : '#444';
                    $is_reply = isset($_GET['reply']) && $_GET['reply'] == $c['id'];
                    
                    echo "<div id='c{$c['id']}' style='margin-left: {$margin}px; margin-top: 10px; border-left: 2px solid $border_color; padding-left: 10px;'>
                        <div style='display:flex; justify-content:space-between; align-items:center; background:#111; padding:5px; border:1px solid #222;'>
                            <span style='color:{$c['chat_color']}; font-weight:bold; font-size:0.75rem;'>{$c['username']}</span>
                            <span style='color:#555; font-size:0.65rem;'>{$c['created_at']}</span>
                        </div>
                        <div style='padding: 8px; color:#ccc; font-size:0.8rem; background:#0a0a0a; border:1px solid #222; border-top:none;'>
                            " . parse_bbcode($c['body']) . "
                            <div style='text-align:right; margin-top:5px;'>
                                <a href='post.php?id={$c['post_id']}&reply={$c['id']}#reply_box' style='font-size:0.65rem; color:#6a9c6a; text-decoration:none;'>[ REPLY ]</a>
                            </div>
                        </div>
                    </div>";
                    
                    render_comments($comments, $c['id'], $depth + 1);
                }
            }
        }
        ?>
        
        <div id="comments" style="margin-top: 30px; border-top: 1px solid #333; padding-top: 20px;">
            <h3 style="color:#e5c07b; font-size:0.9rem; margin-bottom:15px;">ENCRYPTED CHATTER (<?= count($all_comments) ?>)</h3>
            
            <?php if(empty($all_comments)): ?>
                <div style="color:#444; font-style:italic;">No signals detected on this frequency.</div>
            <?php else: ?>
                <?php render_comments($all_comments, null, 0); ?>
            <?php endif; ?>
        </div>

        <div id="reply_box" style="margin-top: 30px; background: #111; border: 1px solid #333; padding: 15px;">
            <?php 
                $reply_id = $_GET['reply'] ?? null;
                $reply_label = "NEW TRANSMISSION";
                if ($reply_id) $reply_label = "REPLYING TO ID #$reply_id";
            ?>
            <div style="color:#6a9c6a; font-size:0.8rem; font-weight:bold; margin-bottom:10px;"><?= $reply_label ?></div>
            
            <form method="POST">
                <?php if($reply_id): ?><input type="hidden" name="parent_id" value="<?= $reply_id ?>"><?php endif; ?>
                <textarea name="comment_body" placeholder="Broadcast message..." required style="width:100%; height:80px; background:#000; color:#fff; border:1px solid #333; padding:10px; box-sizing:border-box; font-family:monospace;"></textarea>
                <div style="margin-top:5px; text-align:right;">
                    <?php if($reply_id): ?>
                        <a href="post.php?id=<?= $id ?>#comments" style="color:#e06c75; font-size:0.7rem; margin-right:10px; text-decoration:none;">[ CANCEL ]</a>
                    <?php endif; ?>
                    <button type="submit" name="post_comment" class="btn-primary" style="width:auto; padding:5px 15px;">TRANSMIT</button>
                </div>
            </form>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="index.php" style="color: #666; font-size: 0.75rem; text-decoration: none;">&lt; RETURN TO FEED</a>
        </div>
    </div>

    <div style="border-top: 1px solid #222; padding: 20px; text-align: center; color: #444; font-size: 0.7rem; background: #161616;">
         Session ID: <?= strtoupper(substr(session_id(), 0, 8)) ?>
    </div>
</div>

</body>
</html>