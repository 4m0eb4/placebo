<?php
// captcha_config.php (Dynamic Version 2.0)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_config.php';

// Fetch Settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) { /* Fallback */ }

// --- GRID SETTINGS ---
$gridW = (int)($settings['captcha_grid_w'] ?? 16);
$gridH = (int)($settings['captcha_grid_h'] ?? 16);
$cellSize = (int)($settings['captcha_cell_size'] ?? 20);

// --- SECURITY SETTINGS ---
$min_sum = (int)($settings['captcha_min_sum'] ?? 8);
$max_sum = (int)($settings['captcha_max_sum'] ?? 12);

// Active Color Pool Settings (Subset Logic)
$active_min = (int)($settings['captcha_active_min'] ?? 3);
$active_max = (int)($settings['captcha_active_max'] ?? 5);

$timer_login = 45;
$timer_register = 60;

// Calculated Total Pixels
$totalW = $gridW * $cellSize;
$totalH = $gridH * $cellSize;
$totalCells = $gridW * $gridH;

// --- COLOR PALETTE ---
$palette_json = $settings['palette_json'] ?? '';
$palette = json_decode($palette_json, true);
if (!$palette) {
    // Fallback if DB fails
    $palette = ['Red'=>[220,10,10], 'Blue'=>[6,7,250], 'Green'=>[0,160,40]];
}

// Function: Selects a subset of colors, then assigns target counts
function get_pattern(array $palette, int $min_targets, int $max_targets, int $subset_min, int $subset_max): array {
    $keys = array_keys($palette);
    if(empty($keys)) return [];
    
    // 1. Determine how many distinct colors will appear in this specific grid
    // Ensure we don't ask for more colors than exist in the palette
    $max_possible = count($keys);
    $real_max = min($subset_max, $max_possible);
    $real_min = min($subset_min, $real_max); // Safety clamp
    
    // Pick the subset count (e.g., use 4 colors out of 12)
    $subset_count = random_int($real_min, $real_max);
    
    // 2. Select the actual colors for this session
    shuffle($keys);
    $active = array_slice($keys, 0, $subset_count);
    
    // 3. Assign target counts (The "Solution")
    // We must distribute $total_targets among our $active colors
    $total_targets = random_int($min_targets, $max_targets);
    
    // Initialize with 1 to ensure every active color appears at least once in the requirements
    // (Optional: You can remove this if you want active colors with 0 requirements, but that's confusing)
    $reqs = array_fill_keys($active, 1);
    
    // Distribute the remaining targets
    $remaining = $total_targets - count($active);
    
    // If we have more targets to assign
    if ($remaining > 0) {
        for($i=0; $i<$remaining; $i++) {
            $k = array_rand($active);
            $reqs[$active[$k]]++;
        }
    } elseif ($remaining < 0) {
        // Edge case: If targets < active colors, simply slice the active array further
        // This shouldn't happen if config is sane, but good for safety
        $reqs = array_slice($reqs, 0, $total_targets);
    }

    return $reqs;
}
?>