<?php
session_start();
require 'db_config.php';
if (!isset($_SESSION['fully_authenticated'])) { header("Location: login.php"); exit; }

$my_id = $_SESSION['user_id'];
$my_name = $_SESSION['username'];

// 1. CREATE GAME
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_size'])) {
    $size = (int)$_POST['create_size'];
    if ($size < 3) $size = 3; if ($size > 6) $size = 6;
    
    $state = [
        'h' => array_fill(0, $size + 1, array_fill(0, $size, 0)),
        'v' => array_fill(0, $size, array_fill(0, $size + 1, 0)),
        'b' => array_fill(0, $size, array_fill(0, $size, 0)),
        's' => ['p1' => 0, 'p2' => 0]
    ];
    
    $uid = bin2hex(random_bytes(8));
    
    $stmt = $pdo->prepare("INSERT INTO games (public_id, p1_id, p1_name, grid_size, board_state) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $my_id, $my_name, $size, json_encode($state)]);
    header("Location: dots.php?id=$uid"); exit;
}

// 2. JOIN GAME
if (isset($_GET['join'])) {
    $uid = $_GET['join'];
    usleep(200000); // 0.2s delay to ensure unique timestamp for stream update
    $stmt = $pdo->prepare("UPDATE games SET p2_id = ?, p2_name = ?, status = 'active', last_move = NOW() WHERE public_id = ? AND p2_id IS NULL AND p1_id != ?");
    $stmt->execute([$my_id, $my_name, $uid, $my_id]);
    header("Location: dots.php?id=$uid"); exit;
}

// FETCH LISTS
$my_games = $pdo->prepare("SELECT * FROM games WHERE (p1_id = ? OR p2_id = ?) AND status != 'finished' ORDER BY last_move DESC");
$my_games->execute([$my_id, $my_id]);

$open_games = $pdo->prepare("SELECT * FROM games WHERE status = 'waiting' AND p1_id != ? ORDER BY created_at DESC");
$open_games->execute([$my_id]);
?>
<!DOCTYPE html>
<html>
<head>
    <title>GAMES</title>
    <link rel="stylesheet" href="style.css">
    <meta http-equiv="refresh" content="15"> <style>
        .g-btn { background:#111; border:1px solid #444; color:#ccc; padding:10px 15px; cursor:pointer; font-weight:bold; }
        .g-btn:hover { background:#6a9c6a; color:#000; }
    </style>
</head>
<body class="<?= $theme_cls ?? '' ?>" style="padding:20px;">
    
    <div style="border-bottom:1px solid #333; margin-bottom:20px; padding-bottom:10px;">
        <span class="term-title">GAME_LOBBY // DOTS</span>
        <a href="index.php" style="float:right; color:#444;">[ EXIT ]</a>
    </div>

    <div class="panel" style="border:1px dashed #444; padding:15px; margin-bottom:30px;">
        <form method="POST">
            <span style="margin-right:15px;">INITIATE NEW SEQUENCE:</span>
            <button type="submit" name="create_size" value="3" class="g-btn">3x3</button>
            <button type="submit" name="create_size" value="4" class="g-btn">4x4</button>
            <button type="submit" name="create_size" value="5" class="g-btn">5x5</button>
            <button type="submit" name="create_size" value="6" class="g-btn">6x6</button>
        </form>
    </div>

    <h3 style="color:#6a9c6a;">// ACTIVE SIGNALS</h3>
    <?php foreach($my_games as $g): ?>
        <div style="background:#161616; padding:10px; border:1px solid #333; margin-bottom:5px; display:flex; justify-content:space-between; align-items:center;">
            <span>ID: <span style="font-family:monospace; color:#e5c07b;"><?= htmlspecialchars($g['public_id']) ?></span> | <span style="color:#e06c75;"><?= htmlspecialchars($g['p1_name']) ?></span> vs <span style="color:#6a9c6a;"><?= htmlspecialchars($g['p2_name'] ?? 'WAITING...') ?></span></span>
            <a href="dots.php?id=<?= $g['public_id'] ?>" class="g-btn" style="text-decoration:none;">[ ENTER ]</a>
        </div>
    <?php endforeach; ?>

    <h3 style="color:#61afef; margin-top:30px;">// OPEN CHALLENGES</h3>
    <?php foreach($open_games as $g): ?>
        <div style="background:#0d0d0d; padding:10px; border:1px solid #222; margin-bottom:5px; display:flex; justify-content:space-between; align-items:center;">
            <span>SIZE: <?= $g['grid_size'] ?>x<?= $g['grid_size'] ?> | HOST: <?= htmlspecialchars($g['p1_name']) ?></span>
            <a href="games.php?join=<?= $g['public_id'] ?>" class="g-btn" style="color:#6a9c6a; text-decoration:none;">[ CONNECT ]</a>
        </div>
    <?php endforeach; ?>
</body>
</html>