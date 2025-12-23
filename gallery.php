<?php
session_start();
require 'db_config.php';

if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// Filter Logic
$view = $_GET['view'] ?? 'image';
$allowed_views = ['image', 'zip', 'doc'];
if (!in_array($view, $allowed_views)) $view = 'image';

// Fetch Data
$stmt = $pdo->prepare("
    SELECT u.*, 
    (SELECT COALESCE(SUM(vote),0) FROM upload_votes WHERE upload_id = u.id) as score,
    (SELECT COUNT(*) FROM upload_comments WHERE upload_id = u.id) as comments
    FROM uploads u 
    WHERE category = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$stmt->execute([$view]);
$files = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>DATA_ARCHIVE</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .nav-tabs { display: flex; border-bottom: 1px solid #333; margin-bottom: 20px; }
        .tab { padding: 10px 20px; color: #666; text-decoration: none; border: 1px solid transparent; border-bottom: none; }
        .tab:hover { color: #aaa; }
        .tab.active { background: #161616; color: #6a9c6a; border-color: #333; border-bottom-color: #161616; margin-bottom: -1px; }
        
        /* Grid for Images */
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .card { background: #111; border: 1px solid #222; padding: 10px; display: block; text-decoration: none; color: #ccc; transition: 0.2s; }
        .card:hover { border-color: #444; background: #1a1a1a; }
        .thumb { height: 100px; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px; color: #444; }
        .thumb img { width: 100%; height: 100%; object-fit: cover; }
        
        /* List for Zips/Docs */
        .list-row { display: flex; justify-content: space-between; padding: 10px; background: #111; border-bottom: 1px solid #222; text-decoration: none; color: #aaa; }
        .list-row:hover { background: #161616; color: #fff; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?> style="padding: 20px;">
    
    <div style="margin-bottom: 20px;">
        <span class="term-title">DATA_ARCHIVE // <?= strtoupper($view) ?>S</span>
        <a href="index.php" style="float:right; color:#444; text-decoration:none;">[ EXIT ]</a>
    </div>

    <div class="nav-tabs">
        <a href="?view=image" class="tab <?= $view==='image'?'active':'' ?>">[ IMAGES ]</a>
        <a href="?view=zip" class="tab <?= $view==='zip'?'active':'' ?>">[ ARCHIVES ]</a>
        <a href="?view=doc" class="tab <?= $view==='doc'?'active':'' ?>">[ DOCUMENTS ]</a>
    </div>

    <?php if($view === 'image'): ?>
        <div class="grid">
            <?php foreach($files as $f): ?>
                <a href="file_view.php?id=<?= $f['id'] ?>" class="card">
                    <div class="thumb">
                        <img src="uploads/image/<?= htmlspecialchars($f['disk_filename']) ?>" loading="lazy">
                    </div>
                    <div style="font-size:0.7rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($f['title'] ?: $f['original_filename']) ?>
                    </div>
                    <div style="font-size:0.6rem; color:#666; margin-top:5px; display:flex; justify-content:space-between;">
                        <span>PTS: <?= $f['score'] ?></span>
                        <span><?= $f['username'] ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="border-top: 1px solid #222;">
            <?php foreach($files as $f): ?>
                <a href="file_view.php?id=<?= $f['id'] ?>" class="list-row">
                    <span>
                        <span style="color: #6a9c6a;">[<?= strtoupper(pathinfo($f['original_filename'], PATHINFO_EXTENSION)) ?>]</span>
                        <?= htmlspecialchars($f['title'] ?: $f['original_filename']) ?>
                    </span>
                    <span style="font-size: 0.75rem; font-family: monospace;">
                        <?= number_format($f['file_size'] / 1024, 1) ?> KB | 
                        PTS: <?= $f['score'] ?> | 
                        BY: <?= $f['username'] ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</body>
</html>