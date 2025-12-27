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

// --- DETERMINISTIC DRAWING LOOP (The Grid) ---
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
            $draw_color = $black; // Fallback
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

// --- HEAVY OBFUSCATION LAYER ---

// Define Noise Colors (Alpha: 0=Opaque, 127=Transparent)
$noise_dark  = imagecolorallocatealpha($im, 0, 0, 0, 75);       // Dark interference
$noise_light = imagecolorallocatealpha($im, 255, 255, 255, 80); // Light interference
$splat_color = imagecolorallocatealpha($im, 30, 30, 30, 60);    // Muddy splatter

// 1. SPLATTER: Random blobs/dots covering the whole area
for ($i = 0; $i < 100; $i++) {
    $cx = rand(0, $imgW);
    $cy = rand(0, $imgH);
    $cw = rand(2, 5);
    $ch = rand(2, 5);
    // Randomly choose between dark mud or light noise
    $col = (rand(0, 1) === 1) ? $splat_color : $noise_light;
    imagefilledellipse($im, $cx, $cy, $cw, $ch, $col);
}

// 2. SCANLINES: Tight horizontal lines
$scan_line = imagecolorallocatealpha($im, 10, 10, 10, 85); 
for ($y = 0; $y < $imgH; $y += 2) { 
    imageline($im, 0, $y, $imgW, $y, $scan_line);
}

// 3. SWIRLS: Large, thick curved arcs
for ($i = 0; $i < 6; $i++) {
    imagesetthickness($im, rand(3, 6)); // VERY BOLD
    
    $cx = rand(-10, $imgW + 10);
    $cy = rand(-10, $imgH + 10);
    $w  = rand(40, 150);
    $h  = rand(40, 150);
    $start = rand(0, 360);
    $end   = $start + rand(90, 200);
    
    // Randomly pick Light or Dark
    $arc_color = (rand(0, 1) === 1) ? $noise_light : $noise_dark;
    
    imagearc($im, $cx, $cy, $w, $h, $start, $end, $arc_color);
}

// 4. SCRATCHES: Aggressive diagonal cuts
for ($i = 0; $i < 20; $i++) {
    imagesetthickness($im, rand(1, 3)); // Varied thickness
    
    $x_start = rand(0, $imgW);
    $y_start = rand(0, $imgH);
    $x_end   = rand(0, $imgW);
    $y_end   = rand(0, $imgH);
    
    $line_color = (rand(0, 1) === 1) ? $noise_light : $noise_dark;
    
    imageline($im, $x_start, $y_start, $x_end, $y_end, $line_color);
}

imagepng($im);
imagedestroy($im);
?>