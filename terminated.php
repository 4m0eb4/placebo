<?php
session_start();
// 1. Nuke the Session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
?>
<!DOCTYPE html>
<html>
<head>
    <title>TERMINATED</title>
    <style>
        html, body {
            background-color: #000;
            height: 100%;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: monospace;
            overflow: hidden;
            cursor: not-allowed;
        }
        .message {
            color: #e06c75;
            text-align: center;
            animation: flicker 2s infinite;
        }
        h1 { font-size: 3rem; margin: 0; letter-spacing: 5px; }
        p { color: #555; font-size: 0.8rem; margin-top: 10px; }
        
        @keyframes flicker {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            52% { opacity: 0.2; }
            54% { opacity: 0.8; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="message">
        <h1>SIGNAL LOST</h1>
        <p>CONNECTION TERMINATED BY HOST</p>
        <p style="color:#222; margin-top:50px;">NO CARRIER</p>
    </div>
</body>
</html>