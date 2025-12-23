<?php
session_start();
require 'db_config.php';

// 1. Auth Check
if (!isset($_SESSION['fully_authenticated'])) { die("<body style='background:#000;color:#555;'>ACCESS DENIED</body>"); }

$msg = '';
$status_color = '#e06c75'; // Red by default

// 2. Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $f = $_FILES['file'];
    $title = trim($_POST['title'] ?? '');
    
    // Config
    $allowed = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
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
        // Security: MIME Check
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        
        // Block PHP/Executables disguised
        $blacklist = ['application/x-php', 'text/x-php', 'application/x-dosexec'];
        if (in_array($mime, $blacklist)) {
            $msg = "Security Alert: Executable detected.";
        } else {
            // Process
            $category = $allowed[$ext];
            $hash_name = bin2hex(random_bytes(16)) . '.' . $ext;
            $target_dir = "uploads/" . $category;
            
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            
            if (move_uploaded_file($f['tmp_name'], "$target_dir/$hash_name")) {
                // DB Insert
                $stmt = $pdo->prepare("INSERT INTO uploads (user_id, username, category, disk_filename, original_filename, file_size, mime_type, title) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'], $_SESSION['username'], $category, 
                    $hash_name, $f['name'], $f['size'], $mime, $title
                ]);
                $msg = "UPLOAD SUCCESSFUL // SORTED INTO: " . strtoupper($category);
                $status_color = '#6a9c6a';
            } else {
                $msg = "Disk Write Failed.";
            }
        }
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
            <br>
            <button type="submit">INITIATE UPLOAD</button>
        </form>
        <div style="margin-top: 20px; font-size: 0.7rem; color: #555;">
            ACCEPTED: Images (JPG/PNG), Archives (ZIP/RAR), Docs (TXT/PDF)
        </div>
    </div>
</body>
</html>