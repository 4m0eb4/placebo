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