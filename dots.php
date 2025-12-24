<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

$gid = $_GET['id'] ?? '';
if (!$gid) { header("Location: games.php"); exit; }

$is_guest = $_SESSION['is_guest'] ?? false;
?>
<!DOCTYPE html>
<html style="height: 100%; overflow: hidden;">
<head>
    <title>DOTS // STREAM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { 
            margin: 0 !important; padding: 0 !important; width: 100vw !important; height: 100vh !important;
            display: flex !important; flex-direction: column !important; background: #0d0d0d !important; overflow: hidden !important;
        }
        .nav-bar { flex-shrink: 0; z-index: 10; border-bottom: 1px solid #333; width: 100%; box-sizing: border-box; background: #161616; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .game-container { flex: 1; display: flex; flex-direction: column; position: relative; overflow: hidden; width: 100%; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>">

    <div class="nav-bar">
        <div style="display:flex; align-items:center; gap: 20px;">
            <div>
                <a href="index.php" class="term-logo">Placebo</a> 
                <span style="color:#333; font-size:0.75rem; font-family:monospace; margin-left:5px;">// Game #<?= $gid ?></span>
            </div>
            <div style="font-size: 0.75rem; font-family: monospace;">
                <?php if (!$is_guest): ?>
                    <a href="links.php" style="color:#888; margin-right:10px; text-decoration:none;">[ LINKS ]</a>
                    <?php if(($_SESSION['rank'] ?? 0) >= 1): ?>
                        <a href="gallery.php" target="_blank" style="color:#6a9c6a; margin-right:10px; text-decoration:none;">[ DATA ]</a>
                    <?php endif; ?>
                    <a href="games.php" style="color:#e5c07b; text-decoration:none;">[ GAMES ]</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="nav-links" style="font-size: 0.75rem; font-family: monospace;">
             <a href="games.php" style="color:#e06c75;">[ EXIT GAME ]</a>
        </div>
    </div>

    <div class="game-container">
        <iframe name="game_stream" src="dots_stream.php?id=<?= $gid ?>" style="width: 100%; height: 100%; border: none;"></iframe>
    </div>

</body>
</html>