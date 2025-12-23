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

// Function: Generates the Grid AND the Requirements together
// Returns ['grid' => [row][col] = 'ColorName', 'req' => ['ColorName' => count]]
function generate_deterministic_grid(array $palette, int $w, int $h, int $min_targets, int $max_targets, int $subset_min, int $subset_max): array {
    $keys = array_keys($palette);
    if(empty($keys)) return ['grid' => [], 'req' => []];
    
    $total_cells = $w * $h;
    
    // 1. Pick Active "Target" Colors (The ones the user must find)
    $max_possible = count($keys);
    $subset_count = random_int(min($subset_min, $max_possible), min($subset_max, $max_possible));
    shuffle($keys);
    $active_targets = array_slice($keys, 0, $subset_count);
    
    // 2. Assign Counts to Targets
    $reqs = [];
    $used_cells = 0;
    
    // Ensure every active target has at least 1
    foreach($active_targets as $color) {
        $reqs[$color] = 1;
        $used_cells++;
    }
    
    // Distribute remaining "required" slots
    $extra_targets = random_int($min_targets, $max_targets) - $used_cells;
    for($i=0; $i < $extra_targets; $i++) {
        $k = $active_targets[array_rand($active_targets)];
        $reqs[$k]++;
        $used_cells++;
    }

    // 3. Fill the Flat Grid
    $flat_grid = [];
    
    // A. Add the Targets
    foreach($reqs as $color => $count) {
        for($i=0; $i < $count; $i++) $flat_grid[] = $color;
    }
    
    // B. Fill the rest with Random Noise from the FULL palette
    $remaining_cells = $total_cells - count($flat_grid);
    $full_palette_keys = array_keys($palette);
    for($i=0; $i < $remaining_cells; $i++) {
        $flat_grid[] = $full_palette_keys[array_rand($full_palette_keys)];
    }
    
    // 4. Shuffle to randomize positions
    shuffle($flat_grid);
    
    // 5. Convert to 2D [Row][Col] for easier drawing/checking
    $grid_2d = [];
    $idx = 0;
    for($r=0; $r < $h; $r++) {
        for($c=0; $c < $w; $c++) {
            $grid_2d[$r][$c] = $flat_grid[$idx++];
        }
    }
    
    return ['grid' => $grid_2d, 'req' => $reqs];
}
?>