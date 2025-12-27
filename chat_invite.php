<?php
session_start();
require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['user_id'])) die("ACCESS DENIED");
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) die("GUESTS CANNOT INVITE");

$user_id = $_SESSION['user_id'];
$my_rank = $_SESSION['rank'] ?? 0;

// --- DYNAMIC RANK CHECK ---
$req_rank = 5;
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'invite_min_rank'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $req_rank = (int)$val;
} catch (Exception $e) {}

if ($my_rank < $req_rank) {
    die("<html><body style='background:#000;color:#e06c75;font-family:monospace;display:flex;justify-content:center;align-items:center;height:100vh;'>
        INSUFFICIENT CLEARANCE (LEVEL $req_rank REQUIRED)
    </body></html>");
}

// CRITICAL FIX: Close session immediately so we don't block the Chat Stream
session_write_close();

$generated_keys = [];
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        require 'db_config.php'; // Re-connect if needed (though PDO persists)
        
        $qty = (int)($_POST['quantity'] ?? 1);
        $hours = (int)($_POST['hours'] ?? 1);
        
        if ($qty < 1) $qty = 1;
        if ($qty > 10) $qty = 10;
        if ($hours < 1) $hours = 1;
        if ($hours > 72) $hours = 72;
        
        $expires = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $stmt = $pdo->prepare("INSERT INTO guest_tokens (token, created_by, expires_at, status) VALUES (?, ?, ?, 'pending')");

        for ($i = 0; $i < $qty; $i++) {
            $token = strtoupper(bin2hex(random_bytes(4)));
            $stmt->execute([$token, $user_id, $expires]);
            $generated_keys[] = $token;
        }

    } catch (Exception $e) {
        $msg = "DB Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
<style>
        /* FULL SCREEN FLEX CENTER */
        html, body { 
            height: 100%; margin: 0; padding: 0; 
            background: transparent; 
            display: flex; justify-content: center; align-items: center;
            font-family: monospace;
        }
        
        .invite-box {
            background: #161616; 
            border: 2px solid #333; 
            
            /* MASSIVE SIZE: 95% of Screen Width, 90% of Height */
            width: 95vw; 
            height: 90vh; 
            max-width: 1600px; /* Cap only on huge 4k screens */
            
            padding: 40px; 
            box-shadow: 0 0 50px rgba(0,0,0,0.9);
            
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            box-sizing: border-box;
        }

        h2 { 
            color: #6a9c6a; margin: 0; 
            border-bottom: 2px solid #333; 
            padding-bottom: 20px; 
            font-size: 1.5rem; 
            letter-spacing: 2px; text-transform: uppercase; 
        }

        /* Standard Sized Inputs centered in the massive box */
        .controls-area {
            display: flex; flex-direction: column; gap: 30px;
            max-width: 600px; margin: 0 auto; width: 100%;
        }

        .control-row { display: flex; gap: 20px; }
        .control-grp { flex: 1; display: flex; flex-direction: column; gap: 10px; }
        
        .control-grp label { font-size: 0.9rem; color: #aaa; font-weight: bold; }
        .control-grp input { 
            background: #080808; border: 1px solid #444; color: #fff; 
            padding: 15px; text-align: center; font-family: monospace; font-size: 1.2rem;
        }
        .control-grp input:focus { border-color: #6a9c6a; outline: none; }

        .btn-primary { 
            padding: 20px; font-size: 1rem; width: 100%; cursor: pointer; 
            background: #222; color: #6a9c6a; border: 1px solid #444; font-weight: bold;
            transition: all 0.2s; letter-spacing: 2px;
        }
        .btn-primary:hover { background: #333; color: #fff; border-color: #6a9c6a; }
        
        /* Grid fills the space */
        .key-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); 
            gap: 15px;
            background: #000; padding: 20px; border: 1px dashed #444;
            overflow-y: auto; flex-grow: 1; 
            margin-bottom: 20px;
        }
        .key-item {
            font-size: 1.2rem; color: #fff; letter-spacing: 3px; 
            background: #111; border: 1px solid #333;
            padding: 15px; text-align: center; user-select: all;
        }
        .key-item:hover { border-color: #6a9c6a; cursor: pointer; background:#222; }
    </style>
</head>
<body>
    <div class="invite-box">
        
        <?php if(!empty($generated_keys)): ?>
            <h2>KEYS GENERATED</h2>
            
            <div class="key-grid">
                <?php foreach($generated_keys as $k): ?>
                    <div class="key-item"><?= $k ?></div>
                <?php endforeach; ?>
            </div>
            
            <p style="color: #aaa; text-align: center; margin-bottom: 20px;">
                Valid for <?= $hours ?> hour<?= $hours > 1 ? 's' : '' ?>.
            </p>
            
            <form action="chat_invite.php" method="get">
                <button type="submit" class="btn-primary">[ BACK ]</button>
            </form>

        <?php else: ?>
            <h2>INVITE GENERATOR</h2>
            
            <form method="POST" class="controls-area" style="margin-top: auto; margin-bottom: auto;">
                <div class="control-row">
                    <div class="control-grp">
                        <label>QUANTITY (MAX 10)</label>
                        <input type="number" name="quantity" value="1" min="1" max="10" required>
                    </div>
                    <div class="control-grp">
                        <label>VALID HOURS</label>
                        <input type="number" name="hours" value="1" min="1" max="72" required>
                    </div>
                </div>

                <div style="font-size: 0.9rem; color: #888; border-left: 4px solid #e06c75; padding: 15px; background: rgba(224, 108, 117, 0.05);">
                    <strong style="color:#e06c75">WARNING:</strong> You are responsible for these keys.
                </div>

                <button type="submit" name="generate" class="btn-primary">>> GENERATE KEYS</button>
            </form>
            
        <?php endif; ?>
        
        <?php if($msg): ?>
            <div style="color:#e06c75; font-size:1rem; text-align:center; padding:10px; font-weight:bold;"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>