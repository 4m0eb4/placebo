<?php
session_start();
require 'db_config.php';

// 1. Auth & Rank Check
if (!isset($_SESSION['fully_authenticated'])) { die("<body style='background:#000;color:#555;'>ACCESS DENIED</body>"); }

// Fetch Upload Perms
$min_up_rank = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'upload_min_rank'")->fetchColumn();
$min_up_rank = (int)($min_up_rank ?: 5); // Default to 5

if (($_SESSION['rank'] ?? 0) < $min_up_rank) {
    die("<!DOCTYPE html><html><body style='background:#000;color:#e06c75;font-family:monospace;display:flex;height:100vh;align-items:center;justify-content:center;'>
        <div style='border:1px solid #e06c75;padding:20px;text-align:center;'>
            <h2 style='margin-top:0;'>CLEARANCE INSUFFICIENT</h2>
            <p>UPLOAD ACCESS REQUIRES RANK $min_up_rank+</p>
        </div>
    </body></html>");
}

$msg = '';
$status_color = '#e06c75'; // Red by default

// 2. Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    try {
        $f = $_FILES['file'];
        $title = trim($_POST['title'] ?? '');
        
        // Config
        $allowed = [
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
            'zip' => 'zip', '7z' => 'zip', 'rar' => 'zip',
            'txt' => 'doc', 'pdf' => 'doc', 'md' => 'doc'
        ];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        // Checks
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $msg = "Upload Error Code: " . $f['error'];
        } elseif ($f['size'] > $max_size) {
            $msg = "File too large (Max 10MB).";
        } elseif (!array_key_exists($ext, $allowed)) {
            $msg = "Filetype not permitted.";
        } else {
            // Security: MIME Check (Robust)
            $mime = 'application/octet-stream';
            try {
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($f['tmp_name']);
                } elseif (function_exists('mime_content_type')) {
                    $mime = mime_content_type($f['tmp_name']);
                }
            } catch (Throwable $e) { $mime = 'application/octet-stream'; }
            
            // Block PHP/Executables disguised
            $blacklist = ['application/x-php', 'text/x-php', 'application/x-dosexec', 'application/x-httpd-php'];
            if (in_array($mime, $blacklist)) {
                $msg = "Security Alert: Executable detected.";
            } else {
                // Process
                $category = $allowed[$ext];
                try { $hash_name = bin2hex(random_bytes(16)) . '.' . $ext; } catch(Exception $e) { $hash_name = uniqid() . '.' . $ext; }
                $target_dir = "uploads/" . $category;
                
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                
                if (move_uploaded_file($f['tmp_name'], "$target_dir/$hash_name")) {
                    // DB Insert
                    $max_v = (int)($_POST['max_views'] ?? 0);
                    $max_d = (int)($_POST['max_dls'] ?? 0);
                    
                    $stmt = $pdo->prepare("INSERT INTO uploads (user_id, username, category, disk_filename, original_filename, file_size, mime_type, title, max_views, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'], $_SESSION['username'], $category, 
                        $hash_name, $f['name'], $f['size'], $mime, $title, $max_v, $max_d
                    ]);
                    $msg = "UPLOAD SUCCESSFUL // SORTED INTO: " . strtoupper($category);
                    $status_color = '#6a9c6a';
                } else {
                    $msg = "Disk Write Failed.";
                }
            }
        }
    } catch (Throwable $e) {
        $msg = "INTERNAL ERROR: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #0d0d0d; padding: 20px; font-family: monospace; color: #ccc; }
        .u-box { border: 1px dashed #333; padding: 20px; text-align: center; }
        /* Default file input styled to fit terminal theme */
        input[type="file"] { 
            background: #000; color: #6a9c6a; border: 1px solid #444; 
            padding: 5px; font-family: monospace; width: 100%; box-sizing: border-box; 
            margin-bottom: 15px; cursor: pointer;
        }
        /* Remove the old custom-file label wrapper styles if present */
        .custom-file:hover { color: #fff; border-color: #666; }
        input[type="text"] { background: #111; border: 1px solid #333; color: #fff; padding: 8px; width: 80%; margin-bottom: 15px; font-family: inherit; }
        button { background: #1a1a1a; color: #6a9c6a; border: 1px solid #333; padding: 8px 20px; font-weight: bold; cursor: pointer; font-family: inherit; }
        button:hover { background: #6a9c6a; color: #000; }
        .status { margin-bottom: 15px; color: <?= $status_color ?>; font-weight: bold; }
    </style>
</head>
<body>
    <div class="u-box">
        <?php if($msg): ?>
            <div class="status"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <label class="custom-file">
                <input type="file" name="file" required onchange="this.parentNode.style.borderColor='#6a9c6a'; this.parentNode.style.color='#6a9c6a';">
                [ SELECT FILE ]
            </label>
            <br>
            <input type="text" name="title" placeholder="Title / Description (Optional)" autocomplete="off">
            
            <div style="margin:10px 0; text-align:left; font-size:0.7rem; color:#666; border-top:1px solid #222; padding-top:5px;">
                <div style="margin-bottom:5px;">AUTO-DELETE CONDITIONS (0 = KEEP FOREVER):</div>
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label>MAX VIEWS:</label>
                        <input type="number" name="max_views" value="0" min="0" style="background:#080808; border:1px solid #333; color:#6a9c6a; width:100%;">
                    </div>
                    <div style="flex:1;">
                        <label>MAX DOWNLOADS:</label>
                        <input type="number" name="max_dls" value="0" min="0" style="background:#080808; border:1px solid #333; color:#56b6c2; width:100%;">
                    </div>
                </div>
            </div>

            <button type="submit">INITIATE UPLOAD</button>
        </form>
        <div style="margin-top: 20px; font-size: 0.7rem; color: #555;">
            ACCEPTED: Images (JPG/PNG), Archives (ZIP/RAR), Docs (TXT/PDF)
        </div>
    </div>
</body>
</html>