<?php
session_start();
require 'db_config.php'; // Required for DB & Theme
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$error = '';
if (!isset($_SESSION['auth_token'])) {
    $_SESSION['auth_token'] = bin2hex(random_bytes(16));
}

// Encrypt the token
// FETCH CUSTOM MESSAGE
$custom_msg = "IDENTITY VERIFICATION REQUIRED"; // Default
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'pgp_message'");
    $stmt->execute();
    if ($row = $stmt->fetch()) $custom_msg = $row['setting_value'];
} catch (Exception $e) {}

$pgp_msg = $custom_msg . "\n\nCHALLENGE TOKEN: " . $_SESSION['auth_token'];
$encrypted_block = "--- PGP ENCRYPTION FAILED (INSTALL PHP-GNUPG) ---\n" . $pgp_msg; // Fallback

if (class_exists('gnupg')) {
    try {
        putenv("GNUPGHOME=/tmp");
        $gpg = new gnupg();
        $gpg->seterrormode(gnupg::ERROR_EXCEPTION);
        $info = $gpg->import($_SESSION['pgp_key']);
        $gpg->addencryptkey($info['fingerprint']);
        $encrypted_block = $gpg->encrypt($pgp_msg);
    } catch (Exception $e) { $error = "PGP Error: " . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['token']) === $_SESSION['auth_token']) {
        $_SESSION['fully_authenticated'] = true;
        unset($_SESSION['auth_token']);
        header("Location: loginsuccess.php");
        exit;
    } else {
        $error = "Invalid Token.";
    }
}
?>
<!DOCTYPE html><html><head><title>2FA</title><link rel="stylesheet" href="style.css"></head>
<body class="<?= $theme_cls ?? '' ?>" <?= $bg_style ?? '' ?>>
<div class="login-wrapper">
    <div class="terminal-header"><span class="term-title">IDENTITY_VERIFICATION</span></div>
    <?php if($error): ?><div class="terminal-alert">! <?= $error ?></div><?php endif; ?>
    
    <form method="POST" class="login-form">
        <div class="input-group">
            <label>ENCRYPTED CHALLENGE</label>
            <textarea readonly class="pgp-box" style="color:#6a9c6a;"><?= htmlspecialchars($encrypted_block) ?></textarea>
        </div>
        <div class="input-group">
            <label>DECRYPTED TOKEN</label>
            <input type="text" name="token" placeholder="Paste token here..." required autocomplete="off">
        </div>
        <button type="submit" class="btn-primary">AUTHENTICATE</button>
        <a href="logout.php" class="link-secondary">ABORT</a>
    </form>
</div></body></html>