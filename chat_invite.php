<?php
session_start();
require 'db_config.php';

// Auth Check
if (!isset($_SESSION['fully_authenticated']) || !isset($_SESSION['user_id'])) die("ACCESS DENIED");
if (isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true) die("GUESTS CANNOT INVITE");

// --- DYNAMIC RANK CHECK ---
// Fetch min rank from settings (Default: 5)
$req_rank = 5;
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'invite_min_rank'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $req_rank = (int)$val;
} catch (Exception $e) {}

if (($_SESSION['rank'] ?? 0) < $req_rank) {
    die("<html><body style='background:#000;color:#e06c75;font-family:monospace;display:flex;justify-content:center;align-items:center;height:100vh;'>
        INSUFFICIENT CLEARANCE (LEVEL $req_rank REQUIRED)
    </body></html>");
}

$token_display = '';
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hours = (int)($_POST['duration'] ?? 1);
    if ($hours < 1) $hours = 1;
    if ($hours > 24) $hours = 24;

    try {
        $token = strtoupper(bin2hex(random_bytes(4)));
        $expires = date('Y-m-d H:i:s', strtotime("+$hours hours"));

        // Insert into DB
        $stmt = $pdo->prepare("INSERT INTO guest_tokens (token, created_by, expires_at, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$token, $_SESSION['user_id'], $expires]);
        
        $token_display = $token;
    } catch (Exception $e) {
        $msg = "DB Error: Ensure database is updated.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { 
            display: flex !important; 
            flex-direction: column !important;
            justify-content: flex-start !important; /* Start at top */
            background: transparent; 
            padding: 10px; 
            text-align: center; 
            height: auto !important; /* Fit content */
            box-sizing: border-box;
            font-family: monospace;
            overflow: hidden;
        }
        /* ULTRA COMPACT FIX */
        .token-box { 
            background: #000; border: 1px dashed #6a9c6a; 
            color: #6a9c6a; padding: 2px; font-size: 0.8rem; 
            margin: 2px 0; letter-spacing: 1px; user-select: all;
            font-weight: bold; font-family: monospace;
        }
        input[type="number"] { 
            background: #000; color: #fff; border: 1px solid #333; 
            padding: 0; width: 30px; text-align: center; outline: none;
            font-family: monospace; font-size: 0.7rem; height: 18px;
        }
        p { margin: 0 0 5px 0; font-size: 0.6rem !important; color:#888; }
        .btn-primary { padding: 2px 6px !important; font-size: 0.65rem !important; width: 100% !important; border-radius: 0; }
        label { font-size: 0.6rem !important; }
        h4 { margin: 0 0 5px 0; font-size: 0.7rem; color: #ccc; }
    </style>
</head>
<body>

    <?php if($token_display): ?>
        <div style="color:#fff; font-size:0.8rem;">TOKEN GENERATED</div>
        <div class="token-box"><?= $token_display ?></div>
        <p style="font-size: 0.75rem; color: #666; margin-bottom:15px;">Valid for <?= $hours ?> Hours.</p>
        
        <form method="POST">
            <button type="submit" class="btn-primary" style="width: auto; padding: 10px 20px;">GENERATE NEW</button>
        </form>

<?php else: ?>
        <div style="display:flex; flex-direction:column; gap:4px;">
            <?php if($msg): ?><div style="color:#e06c75; font-size:0.6rem;"><?= $msg ?></div><?php endif; ?>
            <form method="POST" style="display:flex; align-items:center; gap:5px; justify-content:center;">
                <label style="color:#6a9c6a;">HRS:</label>
                <input type="number" name="duration" value="1" min="1" max="24" required>
                <button type="submit" class="btn-primary" style="flex:1;">GENERATE</button>
            </form>
        </div>
    <?php endif; ?>

</body>
</html>