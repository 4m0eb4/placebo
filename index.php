<?php
session_start();

// 1. Security & Auth
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}
if (!isset($_SESSION['fully_authenticated']) || $_SESSION['fully_authenticated'] !== true) {
    header("Location: login.php"); exit;
}

require 'db_config.php';
require 'bbcode.php'; 

$my_rank = $_SESSION['rank'] ?? 1;

// 2. Fetch Posts (Pinned > Weight > Date)
// We use a try-catch block to handle cases where DB columns might be missing
try {
    $sql = "SELECT p.*, u.username 
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.min_rank <= ? 
            ORDER BY p.is_pinned DESC, p.pin_weight DESC, p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$my_rank]);
} catch (Exception $e) {
    // Fallback Query (Old sorting)
    $sql = "SELECT p.*, u.username 
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.min_rank <= ? 
            ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$my_rank]);
}

$posts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Placebo | Main</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* MAIN LAYOUT */
        .main-container { width: 800px; margin: 0 auto; display: block; }
        .nav-bar { background: #161616; border-bottom: 1px solid #333; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav-links a { color: #888; margin-left: 20px; font-size: 0.8rem; }
        .nav-links a:hover { color: #fff; }
        .content-area { padding: 30px; background: #0d0d0d; min-height: 90vh; }
        
        /* POST CONTAINER */
        .blog-post { 
            background: #121212; 
            border: 1px solid #222; 
            padding: 20px; 
            margin-bottom: 25px; 
            border-radius: 4px; 
        }
        
        /* PREVIEW BOX (CSS CLAMP) */
        /* This forces the post to be a specific height regardless of content length */
        .post-preview-box {
            max-height: 180px;      
            overflow: hidden;       
            position: relative;     
            margin-bottom: 15px;
            color: #aaa; 
            font-size: 0.9rem; 
            line-height: 1.5;
        }
        
        /* Fade effect at the bottom */
        .preview-fade {
            position: absolute; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            height: 50px; 
            background: linear-gradient(to bottom, transparent, #121212);
            pointer-events: none;
        }

        .blog-title { color: #e0e0e0; font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; }
        .blog-meta { color: #555; font-size: 0.7rem; margin-bottom: 15px; border-bottom: 1px solid #222; padding-bottom: 10px; }
        .badge-rank { display:inline-block; padding:2px 6px; font-size:0.6rem; border:1px solid #333; border-radius:3px; margin-left:10px; color:#d19a66; }
        .footer { border-top: 1px solid #222; padding: 20px; text-align: center; color: #444; font-size: 0.7rem; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display: block;"> 

<div class="main-container">
    <div class="nav-bar">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a>
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Home</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <a href="chat.php" style="color:#888; margin-right:10px; text-decoration:none;">[ CHAT ]</a>
                <a href="links.php" style="color:#888; margin-right:10px; text-decoration:none;">[ LINKS ]</a>
                <a href="games.php" style="color:#888; text-decoration:none;">[ GAMES ]</a>
            </div>
        </div>
        <div class="nav-links">
            <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                <a href="admin_dash.php" style="color: var(--accent-secondary);">[ ADMIN ]</a>
            <?php endif; ?>
            <a href="settings.php" style="color:#d19a66; text-decoration:none;">( <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?> )</a>
            <a href="logout.php">LOGOUT</a>
        </div>
    </div>

    <div class="content-area">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 30px;">
            <h2 style="color: #6a9c6a; margin: 0;">News</h2>
            <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                <a href="create_post.php" class="btn-primary" style="width:auto; padding: 8px 15px; font-size: 0.7rem;">+ New Post</a>
            <?php endif; ?>
        </div>
        
        <?php if(count($posts) === 0): ?>
            <div style="color:#555; font-style:italic;">No posts found. (Or you lack clearance).</div>
        <?php endif; ?>

        <?php foreach($posts as $p): ?>
            <?php 
                // --- DISPLAY LOGIC (SAFE MODE) ---
                
                // 1. PIN ICON
                // Use isset to prevent warnings if column missing
                $pin_icon = (!empty($p['is_pinned'])) ? "<span style='color:#e5c07b; margin-right:5px;'>📌</span>" : "";
                
                // 2. CLEARANCE LABEL
                $clearance_label = ($p['min_rank'] > 1) ? "<span class='badge-rank'>LEVEL {$p['min_rank']}</span>" : "";
                
                // 3. TITLE CAP (Strict 60 Chars)
                // Switched to standard strlen/substr to prevent crashes
                $raw_title = strip_tags($p['title']);
                if(strlen($raw_title) > 60) {
                    $display_title = substr($raw_title, 0, 60) . "...";
                } else {
                    $display_title = $raw_title;
                }
            ?>
            
            <div class="blog-post">
                <div class="blog-title" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                    <div style="flex-grow: 1; overflow-wrap: anywhere; word-break: normal; line-height: 1.3; max-width: 85%;">
                        <a href="post.php?id=<?= $p['id'] ?>" style="color:#e0e0e0; text-decoration:none;">
                            <?= $pin_icon . htmlspecialchars($display_title) ?>
                        </a>
                        <?= $clearance_label ?>
                    </div>
                    
                    <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                        <a href="create_post.php?edit=<?= $p['id'] ?>" style="flex-shrink: 0; font-size: 0.6rem; color: #555; text-decoration: none; border: 1px solid #333; padding: 4px 8px; white-space: nowrap;">[ EDIT ]</a>
                    <?php endif; ?>
                </div>

                <div class="blog-meta">
                    AUTH: <?= htmlspecialchars($p['username']) ?> | 
                    DATE: <?= date('M d, H:i', strtotime($p['created_at'])) ?>
                </div>

                <div class="post-preview-box">
                    <div style="overflow-wrap: anywhere;">
                        <?= parse_bbcode($p['body']) ?>
                    </div>
                    <div class="preview-fade"></div>
                </div>

                <div style="margin-top: 5px;">
                    <a href="post.php?id=<?= $p['id'] ?>" style="color: #6a9c6a; font-size: 0.8rem; font-weight: bold; text-decoration:none;">
                        [ ACCESS TRANSMISSION ]
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="footer">
         Session ID: <?= strtoupper(substr(session_id(), 0, 8)) ?>
    </div>
</div>
</body>
</html>