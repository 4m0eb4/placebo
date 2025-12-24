<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// CHECK: Dynamic Rank Requirement
$req_rank = 5; // Default
try {
    $r_stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'links_min_rank'");
    if ($val = $r_stmt->fetchColumn()) $req_rank = (int)$val;
} catch (Exception $e) {}

$my_rank = $_SESSION['rank'] ?? 0;
if ($my_rank < $req_rank) {
    header("Location: chat.php"); exit;
}

// FETCH CATEGORIES FOR NAV
$cats = $pdo->query("SELECT * FROM link_categories ORDER BY display_order ASC")->fetchAll();

// BUILD QUERY
$sql = "SELECT sl.*, lc.name as cat_name 
    FROM shared_links sl 
    LEFT JOIN link_categories lc ON sl.category_id = lc.id 
    WHERE sl.status = 'approved'";

$params = [];
if (isset($_GET['cat']) && is_numeric($_GET['cat'])) {
    $sql .= " AND sl.category_id = ?";
    $params[] = $_GET['cat'];
}

// Quick Search Filter
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $sql .= " AND (sl.title LIKE ? OR sl.url LIKE ?)";
    $term = '%' . $_GET['q'] . '%';
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY lc.display_order ASC, sl.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_links = $stmt->fetchAll();

// GROUP BY CATEGORY
$grouped_links = [];
foreach($raw_links as $l) {
    $cat = $l['cat_name'] ?? 'General'; // Default to 'General'
    $grouped_links[$cat][] = $l;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Uplinks</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .link-card { 
            background: #121212; border: 1px solid #222; 
            padding: 10px 15px; margin-bottom: 6px; 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .link-col { display: flex; flex-direction: column; gap: 4px; max-width: 75%; }
        .link-title { color: #fff; font-weight: bold; font-size: 0.85rem; }
        .link-url { color: #6a9c6a; font-family: monospace; font-size: 0.75rem; text-decoration: none; }
        .link-url:hover { text-decoration: underline; color: #fff; }
        .link-meta { 
            display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
            min-width: 120px;
        }
        .meta-tag {
            background: #1a1a1a; border: 1px solid #333; 
            padding: 2px 6px; border-radius: 2px;
            color: #888; font-size: 0.6rem; font-family: monospace;
            text-transform: uppercase;
            display: inline-block;
            text-align: center;
        }
        .meta-cat { color: #d19a66; border-color: #444; }
        .meta-src { color: #5c6370; }
        .meta-date { color: #444; border: none; background: transparent; padding: 0; }
        .cat-header {
            color: #d19a66; font-family: monospace; 
            border-bottom: 1px solid #333; margin: 25px 0 10px 0; 
            padding-bottom: 5px; font-size: 0.9rem; text-transform: uppercase;
        }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="display: block;">

<div class="main-container" style="width: 800px; margin: 0 auto;">
    <div class="nav-bar" style="background: #161616; border-bottom: 1px solid #333; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a>
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Uplinks</span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <a href="chat.php" style="color:#888; margin-right:10px; text-decoration:none;">[ CHAT ]</a>
                <a href="index.php" style="color:#888; text-decoration:none;">[ HOME ]</a>
            </div>
        </div>
        <div class="nav-links" style="font-size:0.75rem; font-family:monospace;">
             <a href="settings.php">[ SETTINGS ]</a>
             <a href="logout.php">LOGOUT</a>
        </div>
    </div>
    
    <div style="background:#0f0f0f; border-bottom:1px solid #333; padding:10px 20px;">
        <form method="GET" style="display:flex; gap:10px;">
            <?php if(isset($_GET['cat'])): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($_GET['cat']) ?>"><?php endif; ?>
            <input type="text" name="q" placeholder="Search Uplinks..." value="<?= htmlspecialchars($_GET['q']??'') ?>" style="background:#000; border:1px solid #333; color:#fff; padding:5px 10px; font-family:monospace; font-size:0.75rem; width:200px;">
            <button type="submit" style="background:#222; border:1px solid #333; color:#6a9c6a; cursor:pointer; font-family:monospace; font-size:0.75rem; padding:0 15px;">SEARCH</button>
            <?php if(isset($_GET['q'])): ?><a href="links.php" style="color:#e06c75; font-size:0.75rem; display:flex; align-items:center; text-decoration:none;">[CLEAR]</a><?php endif; ?>
        </form>
    </div>

    <div style="background:#111; border-bottom:1px solid #333; padding:10px 20px; display:flex; gap:15px; flex-wrap:wrap; font-family:monospace; font-size:0.75rem;">
        <a href="links.php" style="color: <?= !isset($_GET['cat']) ? '#fff' : '#666' ?>; text-decoration:none;">[ALL]</a>
        <?php foreach($cats as $c): ?>
            <a href="?cat=<?= $c['id'] ?>" style="color: <?= (isset($_GET['cat']) && $_GET['cat'] == $c['id']) ? '#e5c07b' : '#888' ?>; text-decoration:none;">
                <?= htmlspecialchars(strtoupper($c['name'])) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="content-area" style="padding: 30px; background: #0d0d0d; min-height: 80vh;">
        
        <?php if(empty($grouped_links)): ?>
            <div style="color:#444; font-style:italic; padding: 20px; text-align: center;">[ NO APPROVED SIGNALS ]</div>
        <?php else: ?>
            
            <?php foreach($grouped_links as $category => $links): ?>
                <div class="cat-header"><?= htmlspecialchars($category) ?></div>
                
                <?php foreach($links as $l): ?>
                <div class="link-card">
                    <div class="link-col">
                        <?php if(!empty($l['title'])): ?>
                            <div class="link-title"><?= htmlspecialchars($l['title']) ?></div>
                        <?php endif; ?>
                        
                        <a href="<?= htmlspecialchars($l['url']) ?>" target="_blank" class="link-url">
                            <?= htmlspecialchars($l['url']) ?>
                        </a>
                    </div>
                    <div class="link-meta">
                        <span class="meta-tag meta-cat"><?= htmlspecialchars($l['cat_name'] ?? 'General') ?></span>
                        <span class="meta-tag meta-src">BY: <?= htmlspecialchars($l['posted_by']) ?></span>
                        <span class="meta-tag meta-date"><?= date('M d', strtotime($l['created_at'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>