<?php
// terminated.php - The Final Landing Page
session_start();
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}
session_destroy();
?>
<!DOCTYPE html>
<html>
<head>
    <title>DISCONNECTED</title>
    <style>
        body { 
            background-color: #000; 
            color: #444; 
            font-family: monospace; 
            display: flex; 
            flex-direction: column;
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            overflow: hidden;
        }
        .term-box { 
            border: 1px solid #333; 
            padding: 40px; 
            background: #050505; 
            text-align: center;
            box-shadow: 0 0 30px rgba(0,0,0,0.5);
        }
        h1 { font-size: 1.5rem; margin: 0 0 10px 0; color: #666; letter-spacing: 2px; }
        p { margin-bottom: 30px; font-size: 0.8rem; }
        
        a.home-btn { 
            color: #888; 
            border: 1px solid #333; 
            padding: 10px 20px; 
            text-decoration: none; 
            font-size: 0.7rem;
            transition: all 0.2s;
        }
        a.home-btn:hover { color: #fff; border-color: #fff; }
    </style>
</head>
<body>
    <div class="term-box">
        <h1>DISCONNECTED</h1>
        <p>SECURE SESSION ENDED</p>
        <a href="index.php" class="home-btn">RETURN TO LOGIN</a>
    </div>
</body>
</html>