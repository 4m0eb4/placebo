<?php
session_start();

if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) {
    header("Location: chat.php"); exit;
}

require 'db_config.php';
require 'bbcode.php'; // Load Engine



if (!isset($_SESSION['fully_authenticated']) || $_SESSION['fully_authenticated'] !== true) {
    header("Location: login.php"); exit;
}

$my_rank = $_SESSION['rank'] ?? 1;

// Fetch Posts: Pinned First, then Date
// NOTE: Ensure 'is_pinned' column exists in posts table.
try {
    $stmt = $pdo->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.min_rank <= ? ORDER BY p.is_pinned DESC, p.created_at DESC");
    $stmt->execute([$my_rank]);
    $posts = $stmt->fetchAll();
} catch (Exception $e) {
    // Fallback if 'is_pinned' missing
    $stmt = $pdo->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.min_rank <= ? ORDER BY p.created_at DESC");
    $stmt->execute([$my_rank]);
    $posts = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Placebo | Main</title>
    <link rel="stylesheet" href="style.css">
<style>
        /* Specific Styles for Main Layout */
        .main-container { width: 800px; margin: 0 auto; display: block; }
        .nav-bar { background: #161616; border-bottom: 1px solid #333; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav-links a { color: #888; margin-left: 20px; font-size: 0.8rem; }
        .nav-links a:hover { color: #fff; }
        .content-area { padding: 30px; background: #0d0d0d; min-height: 80vh; }
        .blog-post { background: #121212; border: 1px solid #222; padding: 20px; margin-bottom: 25px; border-radius: 4px; }
        .blog-title { color: #e0e0e0; font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; }
        .blog-meta { color: #555; font-size: 0.7rem; margin-bottom: 15px; border-bottom: 1px solid #222; padding-bottom: 10px; }
        /* BBCode Styles within Post */
        .blog-body { color: #aaa; font-size: 0.9rem; line-height: 1.5; }
        .footer { border-top: 1px solid #222; padding: 20px; text-align: center; color: #444; font-size: 0.7rem; }
        .badge-rank { display:inline-block; padding:2px 6px; font-size:0.6rem; border:1px solid #333; border-radius:3px; margin-left:10px; color:#d19a66; }
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
            <a href="settings.php">[ SETTINGS ]</a>
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
                // ROBUST PREVIEW LOGIC
                $full_body = $p['body'];
                $cutoff = $p['preview_cutoff'] ?? 250;
                $has_more = false;
                
                // 1. Safe Strip for calculation
                $clean_text = strip_tags(preg_replace('/\[\/?[^\]]*\]/', '', $full_body));
                
                // 2. Determine Display Mode
                if (strlen($clean_text) > $cutoff) {
                    $has_more = true;
                    // Show TEXT ONLY preview to avoid unclosed tags breaking the page
                    $snippet = mb_substr($clean_text, 0, $cutoff);
                    $preview_html = nl2br(htmlspecialchars($snippet)) . "...";
                } else {
                    // Short post: Show fully rendered BBCode
                    // Use a div wrapper to contain any potential CSS leaks
                    $preview_html = '<div class="bb-render">' . parse_bbcode($full_body) . '</div>';
                }

                $clearance_label = "";
                if($p['min_rank'] > 1) $clearance_label = "<span class='badge-rank'>LEVEL {$p['min_rank']}</span>";
                $pin_icon = ($p['is_pinned']??0) ? "📌 " : "";
            ?>
                    <div class="blog-title" style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <a href="post.php?id=<?= $p['id'] ?>" style="color:#e0e0e0; text-decoration:none;"><?= $pin_icon . parse_bbcode($p['title']) ?></a>
                        <?= $clearance_label ?>
                    </div>
                    
                    <?php if(isset($_SESSION['rank']) && $_SESSION['rank'] >= 9): ?>
                        <a href="create_post.php?edit=<?= $p['id'] ?>" style="font-size: 0.6rem; color: #555; text-decoration: none; border: 1px solid #333; padding: 2px 6px;">[ EDIT ]</a>
                    <?php endif; ?>
                </div>
                <div class="blog-meta">AUTH: <?= htmlspecialchars($p['username']) ?> | DATE: <?= date('M d, H:i', strtotime($p['created_at'])) ?></div>
                <div class="blog-body">
                    <?= $preview_html ?>
                </div>
                <?php if($has_more): ?>
                    <div style="margin-top: 15px;">
                        <a href="post.php?id=<?= $p['id'] ?>" style="color: #6a9c6a; font-size: 0.8rem; font-weight: bold;">[ See full post ]</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="footer">
         Session ID: <?= strtoupper(substr(session_id(), 0, 8)) ?>
    </div>
</div>
</body></html>