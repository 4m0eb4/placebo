<?php
session_start();
// Prevent PHP errors from corrupting the image header
error_reporting(0); 
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

// Pre-allocate a fallback black color for errors/empty slots
$black = imagecolorallocate($im, 0, 0, 0);

// --- DETERMINISTIC DRAWING LOOP ---
// We use the grid stored in SESSION.
$grid_data = $_SESSION['captcha_grid'] ?? [];

for ($row = 0; $row < $gridH; $row++) {
    for ($col = 0; $col < $gridW; $col++) {
        
        // 1. Get the color name for this specific cell
        $color_name = $grid_data[$row][$col] ?? null;
        
        // 2. Resolve the GD color identifier
        if ($color_name && isset($colors[$color_name])) {
            $draw_color = $colors[$color_name];
        } else {
            $draw_color = $black; // Fallback if grid is out of sync
        }
        
        // 3. Calculate coordinates
        $x1 = $col * $cellSize;
        $y1 = $row * $cellSize;
        $x2 = $x1 + ($cellSize - 1); 
        $y2 = $y1 + ($cellSize - 1);

        // 4. Draw the pixel
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $draw_color);
    }
}

imagepng($im);
imagedestroy($im);
?>