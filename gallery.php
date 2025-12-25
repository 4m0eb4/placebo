<?php
session_start();
require 'db_config.php';

if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

// PERMISSION CHECK (Dynamic Setting)
$g_req = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'gallery_min_rank'")->fetchColumn();
$g_req = (int)($g_req ?: 5); // Default to 5 if not set

if (($_SESSION['rank'] ?? 0) < $g_req) {
    die("
    <body style='background:#0d0d0d; color:#e06c75; font-family:monospace; display:flex; align-items:center; justify-content:center; height:100vh;'>
        <div style='border:1px solid #e06c75; padding:20px; text-align:center;'>
            <h2 style='margin:0;'>ACCESS DENIED</h2>
            <p>CLEARANCE LEVEL $g_req REQUIRED.</p>
            <a href='index.php' style='color:#fff;'>[ RETURN ]</a>
        </div>
    </body>");
}

// Filter Logic
$view = $_GET['view'] ?? 'image';
$sort = $_GET['sort'] ?? 'new'; // 'new' or 'top'
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

$allowed_views = ['image', 'zip', 'doc'];
if (!in_array($view, $allowed_views)) $view = 'image';

// Sort Logic
$order_sql = "created_at DESC";
if ($sort === 'top') {
    $order_sql = "score DESC, created_at DESC";
}

// SEARCH LOGIC
$q = trim($_GET['q'] ?? '');
$params = [$view];
$search_sql = "";
if ($q) {
    $search_sql = "AND (title LIKE ? OR original_filename LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// Fetch Data
$sql = "
    SELECT u.*, 
    (SELECT COALESCE(SUM(vote),0) FROM upload_votes WHERE upload_id = u.id) as score,
    (SELECT COUNT(*) FROM upload_comments WHERE upload_id = u.id) as comments
    FROM uploads u 
    WHERE category = ? $search_sql
    ORDER BY $order_sql 
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll();

// Check for next page
$check_next = $pdo->prepare("SELECT id FROM uploads WHERE category = ? LIMIT 1 OFFSET ?");
$check_next->execute([$view, $offset + $per_page]);
$has_next = $check_next->fetchColumn();
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
    
    <div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <span class="term-title">DATA_ARCHIVE // <?= strtoupper($view) ?>S</span>
        </div>
        
        <form method="GET" style="display:flex; gap:5px;">
            <input type="hidden" name="view" value="<?= $view ?>">
            <input type="hidden" name="sort" value="<?= $sort ?>">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search..." style="background:#000; color:#fff; border:1px solid #333; padding:5px; font-family:monospace;">
            <button type="submit" style="background:#1a1a1a; border:1px solid #333; color:#6a9c6a; cursor:pointer;">&gt;</button>
        </form>

        <a href="index.php" style="color:#444; text-decoration:none;">[ EXIT ]</a>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-bottom:1px solid #333; margin-bottom:20px;">
        <div class="nav-tabs" style="border:none; margin:0;">
            <a href="?view=image&sort=<?= $sort ?>" class="tab <?= $view==='image'?'active':'' ?>">[ IMAGES ]</a>
            <a href="?view=zip&sort=<?= $sort ?>" class="tab <?= $view==='zip'?'active':'' ?>">[ ARCHIVES ]</a>
            <a href="?view=doc&sort=<?= $sort ?>" class="tab <?= $view==='doc'?'active':'' ?>">[ DOCUMENTS ]</a>
        </div>
        <div style="padding-bottom:10px; font-size:0.7rem;">
            SORT: 
            <a href="?view=<?= $view ?>&sort=new" style="color:<?= $sort=='new'?'#fff':'#666' ?>; text-decoration:none; margin-right:5px;">[ NEWEST ]</a>
            <a href="?view=<?= $view ?>&sort=top" style="color:<?= $sort=='top'?'#fff':'#666' ?>; text-decoration:none;">[ HIGHEST RATED ]</a>
        </div>
    </div>

    <?php if($view === 'image'): ?>
        <div class="grid">
            <?php foreach($files as $f): ?>
                <a href="image_viewer.php?id=<?= $f['id'] ?>" class="card">
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
            <?php foreach($files as $f): 
                $target_page = ($view === 'zip') ? 'archive_view.php' : 'file_view.php';
            ?>
                <a href="<?= $target_page ?>?id=<?= $f['id'] ?>" class="list-row">
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
<div style="margin-top:20px; display:flex; justify-content:center; gap:5px; font-family:monospace; flex-wrap:wrap;">
        <?php if($page > 1): ?>
            <a href="?view=<?= $view ?>&sort=<?= $sort ?>&page=<?= $page - 1 ?>" class="tab" style="border:1px solid #333;">&lt;</a>
        <?php endif; ?>

        <?php 
        // Simple Tabbed Pagination (Current +/- 3)
        $start = max(1, $page - 3);
        $end = $has_next ? $page + 3 : $page; // Loose estimation since we don't have total count
        
        for ($i = $start; $i <= $end; $i++): 
            $active_style = ($i === $page) ? "background:#6a9c6a; color:#000; border-color:#6a9c6a;" : "border:1px solid #333;";
        ?>
            <a href="?view=<?= $view ?>&sort=<?= $sort ?>&page=<?= $i ?>" class="tab" style="<?= $active_style ?>"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if($has_next): ?>
            <a href="?view=<?= $view ?>&sort=<?= $sort ?>&page=<?= $page + 1 ?>" class="tab" style="border:1px solid #333;">&gt;</a>
        <?php endif; ?>
    </div>
</body>
</html>