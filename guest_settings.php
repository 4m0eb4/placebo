<?php
session_start();
require 'db_config.php';

// --- SECURITY: STRICT DB CHECK (Trap Door) ---
// Checks if session is valid AND if the specific guest token is still active in the DB.
// Prevents the "Feedback Loop" where revoked guests could still access this page.
$kill = false;
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['is_guest']) || $_SESSION['is_guest'] !== true) {
    $kill = true;
} else {
    // Verify Guest Token Status Real-Time
    $stmt = $pdo->prepare("SELECT status FROM guest_tokens WHERE id = ?");
    $stmt->execute([$_SESSION['guest_token_id'] ?? 0]);
    if ($stmt->fetchColumn() !== 'active') $kill = true;
}

if ($kill) {
    echo "<style>body{margin:0;padding:0;background:#000;display:flex;align-items:center;justify-content:center;height:100vh;}</style>";
    echo "<a href='terminated.php' target='_top' style='color:#e06c75;font-family:monospace;font-weight:bold;text-decoration:none;border:1px solid #e06c75;padding:15px 30px;background:#1a0505;'>ACCESS DENIED // EXIT</a>";
    exit;
}

$msg = "";
$my_user = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update Color
    if (isset($_POST['set_color'])) {
        $c = $_POST['color_choice'];
        if (preg_match('/^#[a-f0-9]{6}$/i', $c)) {
            $_SESSION['guest_color'] = $c;
            $msg = "Color Updated.";
        }
    }
    // 2. Delete Own Messages (INSTANT SIGNAL)
    if (isset($_POST['delete_mine'])) {
        // A. Get IDs first so we can signal them
        $stmt = $pdo->prepare("SELECT id FROM chat_messages WHERE username = ?");
        $stmt->execute([$my_user]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // B. Delete from DB
        $pdo->prepare("DELETE FROM chat_messages WHERE username = ?")->execute([$my_user]);

        // C. Send Signals (Forces all active chat screens to hide these messages instantly)
        if ($ids) {
            $sig = $pdo->prepare("INSERT INTO chat_signals (signal_type, signal_val) VALUES ('DELETE', ?)");
            foreach ($ids as $id) {
                $sig->execute([$id]);
            }
        }
        $msg = "TRACES ERASED.";
    }
}

$curr_color = $_SESSION['guest_color'] ?? '#888888';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Guest Config</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<style>
    /* Override style to remove gaps and compact the frame */
    body { background: transparent !important; margin: 0; padding: 0; overflow: hidden; }
    .login-wrapper { 
        width: 100% !important; max-width: 320px; /* Reduced Width */
        margin: 0 auto !important; border: none !important; 
        background: transparent !important; box-shadow: none !important; 
    }
    .input-group { margin-bottom: 10px !important; }
    .btn-primary { padding: 8px !important; font-size: 0.7rem !important; }
    label { font-size: 0.65rem !important; margin-bottom: 3px !important; }
</style>

<div class="login-wrapper">
    <div style="padding: 15px;">
        <?php if($msg): ?><div class="success" style="padding:5px; font-size:0.7rem; margin-bottom:10px;"><?= $msg ?></div><?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>IDENTIFIER COLOR</label>
                <div style="display:flex; gap:5px;">
                    <input type="color" name="color_choice" value="<?= $curr_color ?>" style="height:30px; width:50px; border:none; padding:0; background:none;">
                    <button type="submit" name="set_color" class="btn-primary" style="flex-grow:1;">SAVE COLOR</button>
                </div>
            </div>
        </form>

        <div style="border-bottom:1px solid #222; margin:10px 0;"></div>

        <form method="POST" onsubmit="return confirm('DELETE ALL HISTORY?');">
            <button type="submit" name="delete_mine" class="btn-primary" style="background:#1a0505; border-color:#e06c75; color:#e06c75;">
                WIPE MY HISTORY
            </button>
        </form>
    </div>
</div>
</body>
</html>