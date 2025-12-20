<?php
session_start();
header("Content-Type: image/png");
require 'captcha_config.php'; 

// Calculate exact dimensions
$imgW = $gridW * $cellSize;
$imgH = $gridH * $cellSize;

$im = imagecreatetruecolor($imgW, $imgH);

// Load Palette
$colors = [];
foreach ($palette as $name => $rgb) {
    $colors[$name] = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

if(isset($_SESSION['grid_seed'])) { srand($_SESSION['grid_seed']); }

// --- FIXED DRAWING LOOP ---
for ($row = 0; $row < $gridH; $row++) {
    for ($col = 0; $col < $gridW; $col++) {
        $key = array_rand($colors);
        
        // DYNAMIC CALCULATION:
        // Start Pixel: $col * $cellSize
        // End Pixel:   Start + ($cellSize - 1)
        // This ensures the color fills the ENTIRE 32x32 slot
        
        $x1 = $col * $cellSize;
        $y1 = $row * $cellSize;
        $x2 = $x1 + ($cellSize - 1); 
        $y2 = $y1 + ($cellSize - 1);

        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $colors[$key]);
    }
}

imagepng($im);
imagedestroy($im);
?>